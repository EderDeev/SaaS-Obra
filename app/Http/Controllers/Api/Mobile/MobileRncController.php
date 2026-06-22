<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Models\Contract;
use App\Models\Disciplina;
use App\Models\Empresa;
use App\Models\Obra;
use App\Models\ProjectDocument;
use App\Models\RelatorioNaoConformidade;
use App\Models\Tenant;
use App\Support\RncPermissions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class MobileRncController extends Controller
{
    private const GRAVIDADES = ['Leve', 'Média', 'Grave', 'Gravíssima'];

    public function bootstrap(Request $request): JsonResponse
    {
        $tenant = $this->mobileTenant($request);
        $user = $request->user();
        $contracts = $tenant->contracts()
            ->orderBy('code')
            ->get(['id', 'tenant_id', 'code', 'name'])
            ->filter(fn (Contract $contract): bool => RncPermissions::can($user, $tenant, RncPermissions::CREATE, $contract))
            ->values();
        $contractIds = $contracts->pluck('id')->all();

        return response()->json([
            'tenant' => ['id' => $tenant->id, 'slug' => $tenant->slug, 'name' => $tenant->name],
            'contracts' => $contracts->values(),
            'obras' => $tenant->obras()
                ->whereIn('contract_id', $contractIds)
                ->orderBy('codigo')
                ->orderBy('nome')
                ->get(['id', 'tenant_id', 'contract_id', 'codigo', 'nome']),
            'empresas' => $tenant->empresas()
                ->whereIn('contract_id', $contractIds)
                ->orderBy('nome')
                ->get(['id', 'tenant_id', 'contract_id', 'nome', 'cnpj', 'sigla']),
            'projects' => $tenant->projectDocuments()
                ->whereIn('contract_id', $contractIds)
                ->orderBy('code')
                ->orderBy('title')
                ->get(['id', 'tenant_id', 'contract_id', 'obra_id', 'title', 'code', 'status']),
            'disciplinas' => $tenant->disciplinas()
                ->whereIn('contract_id', $contractIds)
                ->orderBy('sigla')
                ->orderBy('nome')
                ->get(['id', 'tenant_id', 'contract_id', 'nome', 'sigla', 'cor']),
            'gravidades' => self::GRAVIDADES,
        ]);
    }

    public function storeOffline(Request $request): JsonResponse
    {
        $tenant = $this->mobileTenant($request);
        $data = $request->validate([
            'local_uuid' => ['required', 'string', 'max:120'],
            'obra_id' => ['required', Rule::exists('obras', 'id')->where(fn ($query) => $query->where('tenant_id', $tenant->id))],
            'project_document_id' => ['nullable', Rule::exists('project_documents', 'id')->where(fn ($query) => $query->where('tenant_id', $tenant->id))],
            'contratante_empresa_id' => ['required', Rule::exists('empresas', 'id')->where(fn ($query) => $query->where('tenant_id', $tenant->id))],
            'contratada_empresa_id' => ['required', Rule::exists('empresas', 'id')->where(fn ($query) => $query->where('tenant_id', $tenant->id))],
            'opened_at' => ['required', 'date'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'disciplina_id' => ['required', Rule::exists('disciplinas', 'id')->where(fn ($query) => $query->where('tenant_id', $tenant->id))],
            'gravidade' => ['required', Rule::in(self::GRAVIDADES)],
            'descricao_problema' => ['required', 'string', 'max:10000'],
            'observacao' => ['nullable', 'string', 'max:10000'],
            'acoes_corretivas_recomendadas' => ['required', 'string', 'max:10000'],
            'prazo_resposta_acao_corretiva' => ['required', 'date', 'after_or_equal:opened_at'],
            'photos' => ['nullable', 'array', 'max:12'],
            'photos.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'photo_comments' => ['nullable', 'array'],
            'photo_comments.*' => ['nullable', 'string', 'max:1000'],
            'photo_positions' => ['nullable', 'array'],
            'photo_positions.*' => ['nullable', 'integer', 'min:1', 'max:999'],
        ]);

        $existing = RelatorioNaoConformidade::query()
            ->where('tenant_id', $tenant->id)
            ->where('mobile_local_uuid', $data['local_uuid'])
            ->first();

        if ($existing) {
            return response()->json([
                'message' => 'RNC mobile já sincronizada anteriormente.',
                'rnc' => $this->serializeRnc($existing),
            ]);
        }

        $obra = $tenant->obras()->findOrFail($data['obra_id']);
        $contract = $obra->contract()->firstOrFail();
        $user = $request->user();

        abort_unless(RncPermissions::can($user, $tenant, RncPermissions::CREATE, $contract), 403);

        $contratante = $this->empresaForContract($tenant, (int) $data['contratante_empresa_id'], $contract);
        $contratada = $this->empresaForContract($tenant, (int) $data['contratada_empresa_id'], $contract);
        $projectDocument = $this->projectDocumentForRnc($tenant, $data['project_document_id'] ?? null, $contract, $obra);
        $disciplina = $this->disciplinaForContract($tenant, (int) $data['disciplina_id'], $contract);

        if (! $contratante || ! $contratada) {
            throw ValidationException::withMessages([
                'contratante_empresa_id' => 'Selecione empresas vinculadas ao mesmo contrato da obra.',
            ]);
        }

        if (! $disciplina) {
            throw ValidationException::withMessages([
                'disciplina_id' => 'Selecione uma disciplina vinculada ao mesmo contrato da obra.',
            ]);
        }

        $sequenceYear = Carbon::parse($data['opened_at'])->year;

        $rnc = DB::transaction(function () use ($tenant, $contract, $obra, $projectDocument, $disciplina, $contratante, $contratada, $user, $data, $sequenceYear): RelatorioNaoConformidade {
            Tenant::query()->whereKey($tenant->id)->lockForUpdate()->firstOrFail();

            $lastSequence = $tenant->relatorioNaoConformidades()
                ->withTrashed()
                ->where('sequence_year', $sequenceYear)
                ->orderByDesc('sequence_number')
                ->lockForUpdate()
                ->value('sequence_number');

            return $tenant->relatorioNaoConformidades()->create([
                'mobile_local_uuid' => $data['local_uuid'],
                'sequence_number' => ((int) $lastSequence) + 1,
                'sequence_year' => $sequenceYear,
                'contract_id' => $contract->id,
                'obra_id' => $obra->id,
                'project_document_id' => $projectDocument?->id,
                'disciplina_id' => $disciplina->id,
                'contratante_empresa_id' => $contratante->id,
                'contratada_empresa_id' => $contratada->id,
                'created_by_id' => $user->id,
                'opened_at' => $data['opened_at'],
                'latitude' => $data['latitude'] ?? null,
                'longitude' => $data['longitude'] ?? null,
                'natureza' => $disciplina->nome,
                'gravidade' => $data['gravidade'],
                'descricao_problema' => $data['descricao_problema'],
                'observacao' => $data['observacao'] ?? null,
                'acoes_corretivas_recomendadas' => $data['acoes_corretivas_recomendadas'],
                'prazo_resposta_acao_corretiva' => $data['prazo_resposta_acao_corretiva'],
                'status' => 'aberta',
            ]);
        });

        $comments = collect($request->input('photo_comments', []));
        $positions = collect($request->input('photo_positions', []));

        foreach ($request->file('photos', []) as $index => $photo) {
            $storedPhoto = $this->storeRncPhotoUpload($photo, $tenant, $rnc);

            $rnc->photos()->create([
                'tenant_id' => $tenant->id,
                'user_id' => $user->id,
                'path' => $storedPhoto['path'],
                'original_name' => $photo->getClientOriginalName(),
                'mime_type' => $storedPhoto['mime_type'],
                'size' => $storedPhoto['size'],
                'position' => (int) ($positions->get($index) ?: $index + 1),
                'comment' => $comments->get($index),
            ]);
        }

        $rnc->loadCount('photos');

        return response()->json([
            'message' => 'RNC sincronizada com sucesso.',
            'rnc' => $this->serializeRnc($rnc),
        ], 201);
    }

    private function empresaForContract(Tenant $tenant, int $empresaId, Contract $contract): ?Empresa
    {
        return $tenant->empresas()
            ->whereKey($empresaId)
            ->where('contract_id', $contract->id)
            ->first();
    }

    private function disciplinaForContract(Tenant $tenant, int $disciplinaId, Contract $contract): ?Disciplina
    {
        return $tenant->disciplinas()
            ->whereKey($disciplinaId)
            ->where('contract_id', $contract->id)
            ->first();
    }

    private function projectDocumentForRnc(Tenant $tenant, mixed $projectDocumentId, Contract $contract, Obra $obra): ?ProjectDocument
    {
        if (blank($projectDocumentId)) {
            return null;
        }

        return $tenant->projectDocuments()
            ->whereKey($projectDocumentId)
            ->where('contract_id', $contract->id)
            ->where(function (Builder $query) use ($obra): void {
                $query->whereNull('obra_id')->orWhere('obra_id', $obra->id);
            })
            ->first();
    }

    private function storeRncPhotoUpload(UploadedFile $photo, Tenant $tenant, RelatorioNaoConformidade $rnc): array
    {
        $path = $photo->store("tenants/{$tenant->id}/rnc/{$rnc->id}", 'public');

        return [
            'path' => $path,
            'mime_type' => $photo->getClientMimeType(),
            'size' => $photo->getSize(),
        ];
    }

    private function serializeRnc(RelatorioNaoConformidade $rnc): array
    {
        return [
            'id' => $rnc->id,
            'formatted_number' => $rnc->formatted_number,
            'status' => $rnc->status,
            'photos_count' => $rnc->photos_count ?? $rnc->photos()->count(),
        ];
    }

    private function mobileTenant(Request $request): Tenant
    {
        $tenant = $request->attributes->get('mobile_tenant');

        abort_unless($tenant instanceof Tenant, 401, 'Ambiente mobile não identificado.');

        return $tenant;
    }
}
