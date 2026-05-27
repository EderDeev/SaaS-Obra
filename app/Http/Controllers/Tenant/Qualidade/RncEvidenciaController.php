<?php

namespace App\Http\Controllers\Tenant\Qualidade;

use App\Http\Controllers\Controller;
use App\Models\Contract;
use App\Models\RelatorioNaoConformidade;
use App\Models\RelatorioNaoConformidadeAcaoCorretiva;
use App\Models\RelatorioNaoConformidadeEvidencia;
use App\Models\RelatorioNaoConformidadeResponsavel;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\RncEvidenceSubmittedNotification;
use App\Support\RncPermissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class RncEvidenciaController extends Controller
{
    public function create(Request $request, Tenant $tenant, RelatorioNaoConformidade $rnc): Response
    {
        $rnc = $this->loadAccessibleRnc($request, $tenant, $rnc);
        $acaoCorretiva = $this->approvedCorrectiveAction($rnc);

        abort_unless($acaoCorretiva, 403);
        abort_unless(RncPermissions::can($request->user(), $tenant, RncPermissions::EVIDENCE, $rnc->contract), 403);
        abort_unless($rnc->finalized_at === null && $rnc->evidencias->isEmpty(), 403);

        return Inertia::render('Tenant/Qualidade/RelatorioNaoConformidade/Evidenciar', [
            'tenant' => $tenant,
            'rnc' => $rnc,
            'acaoCorretiva' => $this->formatCorrectiveAction($acaoCorretiva),
        ]);
    }

    public function store(Request $request, Tenant $tenant, RelatorioNaoConformidade $rnc): RedirectResponse
    {
        $rnc = $this->loadAccessibleRnc($request, $tenant, $rnc);
        $acaoCorretiva = $this->approvedCorrectiveAction($rnc);

        abort_unless($acaoCorretiva, 403);
        abort_unless(RncPermissions::can($request->user(), $tenant, RncPermissions::EVIDENCE, $rnc->contract), 403);
        abort_unless($rnc->finalized_at === null && $rnc->evidencias->isEmpty(), 403);

        $data = $request->validate([
            'evidence_photos' => ['required', 'array', 'min:1', 'max:12'],
            'evidence_photos.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'evidence_photo_comments' => ['nullable', 'array'],
            'evidence_photo_comments.*' => ['nullable', 'string', 'max:1000'],
            'evidence_photo_positions' => ['nullable', 'array'],
            'evidence_photo_positions.*' => ['nullable', 'integer', 'min:1', 'max:999'],
            'attachment' => ['required', 'file', 'mimes:zip', 'max:30720'],
        ], [
            'evidence_photos.required' => 'Envie pelo menos uma imagem evidenciando a correcao.',
            'evidence_photos.*.image' => 'Envie apenas imagens nas evidencias.',
            'evidence_photos.*.max' => 'Cada imagem pode ter no maximo 5 MB.',
            'evidence_photo_comments.*.max' => 'O comentario de cada imagem pode ter no maximo 1000 caracteres.',
            'attachment.required' => 'Envie o documento zipado das evidencias.',
            'attachment.mimes' => 'O documento precisa ser um arquivo .zip.',
            'attachment.max' => 'O documento pode ter no maximo 30 MB.',
        ]);

        $zip = $data['attachment'];
        $comments = $data['evidence_photo_comments'] ?? [];
        $positions = $data['evidence_photo_positions'] ?? [];

        $evidencia = DB::transaction(function () use ($tenant, $rnc, $acaoCorretiva, $request, $zip, $comments, $positions): RelatorioNaoConformidadeEvidencia {
            $zipPath = $zip->store("tenant-{$tenant->id}/rnc/{$rnc->id}/evidencias", 'public');

            $evidencia = $rnc->evidencias()->create([
                'tenant_id' => $tenant->id,
                'relatorio_nao_conformidade_acao_corretiva_id' => $acaoCorretiva->id,
                'user_id' => $request->user()->id,
                'attachment_path' => $zipPath,
                'attachment_original_name' => $zip->getClientOriginalName(),
                'attachment_mime_type' => $zip->getClientMimeType(),
                'attachment_size' => $zip->getSize(),
                'submitted_at' => now(),
            ]);

            foreach ($request->file('evidence_photos', []) as $index => $photo) {
                $storedPhoto = $this->storeEvidencePhotoUpload($photo, $tenant, $rnc);

                $evidencia->photos()->create([
                    'tenant_id' => $tenant->id,
                    'user_id' => $request->user()->id,
                    'path' => $storedPhoto['path'],
                    'original_name' => $photo->getClientOriginalName(),
                    'mime_type' => $storedPhoto['mime_type'],
                    'size' => $storedPhoto['size'] ?? $photo->getSize(),
                    'position' => (int) ($positions[$index] ?? $index + 1),
                    'comment' => $comments[$index] ?? null,
                ]);
            }

            $rnc->forceFill([
                'status' => 'finalizada',
                'finalized_at' => now(),
                'finalized_by_id' => $request->user()->id,
            ])->save();

            return $evidencia->loadMissing([
                'tenant',
                'rnc.tenant',
                'rnc.contract',
                'rnc.obra',
                'rnc.contratada',
                'acaoCorretiva',
                'photos',
            ]);
        });

        $notification = new RncEvidenceSubmittedNotification($evidencia, $request->user());

        $this->linkedUsersForContract($tenant, $rnc->contract)
            ->each(fn (User $user) => $user->notify($notification));

        return redirect()
            ->route('tenant.qualidade.rnc.index', $tenant)
            ->with('success', 'Evidencias enviadas. RNC finalizada e usuarios vinculados notificados.');
    }

    public function download(Request $request, Tenant $tenant, RelatorioNaoConformidade $rnc, RelatorioNaoConformidadeEvidencia $evidencia): StreamedResponse
    {
        $rnc = $this->loadAccessibleRnc($request, $tenant, $rnc);

        abort_unless((int) $evidencia->tenant_id === (int) $tenant->id, 404);
        abort_unless((int) $evidencia->relatorio_nao_conformidade_id === (int) $rnc->id, 404);
        abort_unless(RncPermissions::can($request->user(), $tenant, RncPermissions::VIEW, $rnc->contract), 403);
        abort_unless(Storage::disk('public')->exists($evidencia->attachment_path), 404);

        return Storage::disk('public')->download(
            $evidencia->attachment_path,
            $evidencia->attachment_original_name ?: 'evidencias-rnc.zip',
            ['Content-Type' => $evidencia->attachment_mime_type ?: 'application/zip'],
        );
    }

    private function loadAccessibleRnc(Request $request, Tenant $tenant, RelatorioNaoConformidade $rnc): RelatorioNaoConformidade
    {
        abort_unless((int) $rnc->tenant_id === (int) $tenant->id, 404);

        $rnc->load([
            'contract:id,tenant_id,code,name,total_value,currency,starts_at,ends_at,city,state',
            'obra:id,tenant_id,contract_id,nome,codigo,tipo',
            'contratante:id,nome,cnpj,sigla,logo_path',
            'contratada:id,nome,cnpj,sigla,logo_path',
            'creator:id,name,email',
            'finalizedBy:id,name,email',
            'photos:id,tenant_id,relatorio_nao_conformidade_id,path,position,comment,original_name,mime_type',
            'acoesCorretivas.user:id,name,email,avatar_url',
            'acoesCorretivas.reviewer:id,name,email,avatar_url',
            'evidencias.user:id,name,email,avatar_url',
            'evidencias.photos:id,tenant_id,relatorio_nao_conformidade_evidencia_id,path,position,comment,original_name,mime_type',
        ]);

        abort_unless($this->canAccessContract($request->user(), $tenant, $rnc->contract), 403);

        return $rnc;
    }

    private function approvedCorrectiveAction(RelatorioNaoConformidade $rnc): ?RelatorioNaoConformidadeAcaoCorretiva
    {
        return $rnc->acoesCorretivas
            ->first(fn (RelatorioNaoConformidadeAcaoCorretiva $acao): bool => $acao->status === 'approved');
    }

    private function canAccessContract(User $user, Tenant $tenant, Contract $contract): bool
    {
        if (in_array($user->tenantRole($tenant), ['tenant_owner', 'tenant_admin'], true)) {
            return true;
        }

        return $contract->participants()
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->exists();
    }

    /**
     * @return Collection<int, User>
     */
    private function linkedUsersForContract(Tenant $tenant, Contract $contract): Collection
    {
        $globalUsers = $tenant->memberships()
            ->where('status', 'active')
            ->whereIn('role', ['tenant_owner', 'tenant_admin'])
            ->with('user:id,name,email')
            ->get()
            ->pluck('user')
            ->filter();

        $participants = $contract->participants()
            ->where('status', 'active')
            ->with('user:id,name,email')
            ->get()
            ->pluck('user')
            ->filter();

        $responsibles = RelatorioNaoConformidadeResponsavel::query()
            ->where('tenant_id', $tenant->id)
            ->where('contract_id', $contract->id)
            ->where('status', 'active')
            ->with('user:id,name,email')
            ->get()
            ->pluck('user')
            ->filter();

        return $globalUsers
            ->merge($participants)
            ->merge($responsibles)
            ->filter(fn (?User $user): bool => $user !== null && $this->canAccessContract($user, $tenant, $contract))
            ->unique('id')
            ->values();
    }

    /**
     * @return array<string, mixed>
     */
    private function formatCorrectiveAction(RelatorioNaoConformidadeAcaoCorretiva $acao): array
    {
        return [
            'id' => $acao->id,
            'descricao_proposta' => $acao->descricao_proposta,
            'prazo_execucao_proposto' => $acao->prazo_execucao_proposto,
            'prazo_execucao_proposto_formatted' => $acao->prazo_execucao_proposto?->format('d/m/Y'),
            'review_observation' => $acao->review_observation,
            'reviewed_at' => $acao->reviewed_at,
            'reviewed_at_formatted' => $acao->reviewed_at?->format('d/m/Y H:i'),
            'user' => $acao->user,
            'reviewer' => $acao->reviewer,
        ];
    }

    private function storeEvidencePhotoUpload(UploadedFile $photo, Tenant $tenant, RelatorioNaoConformidade $rnc): array
    {
        $directory = "tenant-{$tenant->id}/rnc/{$rnc->id}/evidencias/photos";
        $mime = strtolower((string) $photo->getClientMimeType());

        if (in_array($mime, ['image/png', 'image/webp'], true)) {
            $convertedPhoto = $this->storeImageAsJpeg($photo->getRealPath(), $directory);

            if ($convertedPhoto) {
                return $convertedPhoto;
            }
        }

        $path = $photo->store($directory, 'public');

        return [
            'path' => $path,
            'mime_type' => $photo->getClientMimeType(),
            'size' => $photo->getSize(),
        ];
    }

    private function storeImageAsJpeg(string $sourcePath, string $directory): ?array
    {
        if (! function_exists('imagecreatefromstring') || ! function_exists('imagejpeg')) {
            return null;
        }

        $source = @imagecreatefromstring((string) file_get_contents($sourcePath));

        if (! $source) {
            return null;
        }

        $width = imagesx($source);
        $height = imagesy($source);
        $canvas = imagecreatetruecolor($width, $height);
        $white = imagecolorallocate($canvas, 255, 255, 255);

        imagefill($canvas, 0, 0, $white);
        imagecopy($canvas, $source, 0, 0, 0, 0, $width, $height);

        Storage::disk('public')->makeDirectory($directory);

        $path = $directory.'/'.Str::random(40).'.jpg';
        $absolutePath = Storage::disk('public')->path($path);
        $saved = imagejpeg($canvas, $absolutePath, 88);

        imagedestroy($source);
        imagedestroy($canvas);

        if (! $saved) {
            return null;
        }

        return [
            'path' => $path,
            'mime_type' => 'image/jpeg',
            'size' => @filesize($absolutePath) ?: null,
        ];
    }
}
