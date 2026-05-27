<?php

namespace App\Http\Controllers\Tenant\Qualidade;

use App\Http\Controllers\Controller;
use App\Models\Contract;
use App\Models\RelatorioNaoConformidade;
use App\Models\RelatorioNaoConformidadeAcaoCorretiva;
use App\Models\RelatorioNaoConformidadeResponsavel;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\RncCorrectiveActionSubmittedNotification;
use App\Notifications\RncCorrectiveActionReviewedNotification;
use App\Support\RncPermissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class RncAcaoCorretivaController extends Controller
{
    public function create(Request $request, Tenant $tenant, RelatorioNaoConformidade $rnc): Response
    {
        $rnc = $this->loadAccessibleRnc($request, $tenant, $rnc);

        abort_unless($rnc->notified_at !== null, 403);
        abort_unless(RncPermissions::can($request->user(), $tenant, RncPermissions::CORRECTIVE_ACTION, $rnc->contract), 403);
        abort_unless($this->canCreateNewProposal($rnc), 403);

        return Inertia::render('Tenant/Qualidade/RelatorioNaoConformidade/AcaoCorretiva', [
            'tenant' => $tenant,
            'rnc' => $rnc,
            'acoesCorretivas' => $rnc->acoesCorretivas
                ->map(fn (RelatorioNaoConformidadeAcaoCorretiva $acao): array => $this->formatCorrectiveAction($acao, $tenant, $rnc))
                ->values(),
        ]);
    }

    public function store(Request $request, Tenant $tenant, RelatorioNaoConformidade $rnc): RedirectResponse
    {
        $rnc = $this->loadAccessibleRnc($request, $tenant, $rnc);

        abort_unless($rnc->notified_at !== null, 403);
        abort_unless(RncPermissions::can($request->user(), $tenant, RncPermissions::CORRECTIVE_ACTION, $rnc->contract), 403);
        abort_unless($this->canCreateNewProposal($rnc), 403);

        $data = $request->validate([
            'descricao_proposta' => ['required', 'string', 'max:10000'],
            'prazo_execucao_proposto' => ['required', 'date'],
            'attachment' => ['required', 'file', 'mimes:zip', 'max:30720'],
        ], [
            'descricao_proposta.required' => 'Descreva a proposta de acao corretiva.',
            'prazo_execucao_proposto.required' => 'Informe o prazo proposto para executar a acao corretiva.',
            'attachment.required' => 'Envie o anexo zipado da proposta.',
            'attachment.mimes' => 'O anexo precisa ser um arquivo .zip.',
            'attachment.max' => 'O anexo pode ter no maximo 30 MB.',
        ]);

        $file = $data['attachment'];
        $path = $file->store("tenant-{$tenant->id}/rnc/{$rnc->id}/acoes-corretivas", 'public');

        $acaoCorretiva = $rnc->acoesCorretivas()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $request->user()->id,
            'descricao_proposta' => $data['descricao_proposta'],
            'prazo_execucao_proposto' => $data['prazo_execucao_proposto'],
            'attachment_path' => $path,
            'attachment_original_name' => $file->getClientOriginalName(),
            'attachment_mime_type' => $file->getClientMimeType(),
            'attachment_size' => $file->getSize(),
            'submitted_at' => now(),
            'status' => 'pending',
        ]);

        $notification = new RncCorrectiveActionSubmittedNotification($acaoCorretiva, $request->user());

        $this->responsibleUsers($tenant, $rnc->contract)
            ->each(fn (User $user) => $user->notify($notification));

        return redirect()
            ->route('tenant.qualidade.rnc.index', $tenant)
            ->with('success', 'Proposta de acao corretiva enviada.');
    }

    public function review(Request $request, Tenant $tenant, RelatorioNaoConformidade $rnc): Response
    {
        $rnc = $this->loadAccessibleRnc($request, $tenant, $rnc);

        abort_unless($rnc->notified_at !== null, 403);
        abort_unless(RncPermissions::can($request->user(), $tenant, RncPermissions::REVIEW, $rnc->contract), 403);

        $acaoCorretiva = $this->latestPendingProposal($rnc);

        abort_unless($acaoCorretiva, 404);

        return Inertia::render('Tenant/Qualidade/RelatorioNaoConformidade/AnalisarProposta', [
            'tenant' => $tenant,
            'rnc' => $rnc,
            'acaoCorretiva' => $this->formatCorrectiveAction($acaoCorretiva, $tenant, $rnc),
            'acoesCorretivas' => $rnc->acoesCorretivas
                ->map(fn (RelatorioNaoConformidadeAcaoCorretiva $acao): array => $this->formatCorrectiveAction($acao, $tenant, $rnc))
                ->values(),
        ]);
    }

    public function submitReview(Request $request, Tenant $tenant, RelatorioNaoConformidade $rnc): RedirectResponse
    {
        $rnc = $this->loadAccessibleRnc($request, $tenant, $rnc);

        abort_unless($rnc->notified_at !== null, 403);
        abort_unless(RncPermissions::can($request->user(), $tenant, RncPermissions::REVIEW, $rnc->contract), 403);

        $data = $request->validate([
            'decision' => ['required', Rule::in(['approved', 'rejected'])],
            'review_observation' => ['required', 'string', 'max:10000'],
        ], [
            'decision.required' => 'Escolha se a proposta foi aprovada ou reprovada.',
            'decision.in' => 'Escolha uma decisao valida.',
            'review_observation.required' => 'Informe as observacoes da analise.',
        ]);

        $acaoCorretiva = $rnc->acoesCorretivas()
            ->where('status', 'pending')
            ->latest('submitted_at')
            ->first();

        abort_unless($acaoCorretiva, 404);

        $acaoCorretiva->forceFill([
            'status' => $data['decision'],
            'review_observation' => $data['review_observation'],
            'reviewed_at' => now(),
            'reviewed_by_id' => $request->user()->id,
        ])->save();

        $acaoCorretiva->loadMissing(['tenant', 'rnc.tenant', 'rnc.contract', 'rnc.obra', 'user', 'reviewer']);

        $notification = new RncCorrectiveActionReviewedNotification($acaoCorretiva, $request->user());

        $this->proposalReviewRecipients($tenant, $rnc->contract, $acaoCorretiva)
            ->each(fn (User $user) => $user->notify($notification));

        $message = $data['decision'] === 'approved'
            ? 'Proposta aprovada. Os responsaveis foram notificados para iniciar o processo corretivo.'
            : 'Proposta reprovada. Os responsaveis foram notificados e a RNC voltou para envio de nova proposta.';

        return redirect()
            ->route('tenant.qualidade.rnc.show', [$tenant, $rnc])
            ->with('success', $message);
    }

    public function download(Request $request, Tenant $tenant, RelatorioNaoConformidade $rnc, RelatorioNaoConformidadeAcaoCorretiva $acaoCorretiva): StreamedResponse
    {
        $rnc = $this->loadAccessibleRnc($request, $tenant, $rnc);

        abort_unless((int) $acaoCorretiva->tenant_id === (int) $tenant->id, 404);
        abort_unless((int) $acaoCorretiva->relatorio_nao_conformidade_id === (int) $rnc->id, 404);
        abort_unless(
            RncPermissions::can($request->user(), $tenant, RncPermissions::REVIEW, $rnc->contract)
                || RncPermissions::can($request->user(), $tenant, RncPermissions::CORRECTIVE_ACTION, $rnc->contract),
            403,
        );
        abort_unless(Storage::disk('public')->exists($acaoCorretiva->attachment_path), 404);

        return Storage::disk('public')->download(
            $acaoCorretiva->attachment_path,
            $acaoCorretiva->attachment_original_name ?: 'acao-corretiva.zip',
            ['Content-Type' => $acaoCorretiva->attachment_mime_type ?: 'application/zip'],
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
            'photos:id,tenant_id,relatorio_nao_conformidade_id,path,position,comment,original_name,mime_type',
            'acoesCorretivas.user:id,name,email,avatar_url',
            'acoesCorretivas.reviewer:id,name,email,avatar_url',
        ]);

        abort_unless($this->canAccessContract($request->user(), $tenant, $rnc->contract), 403);

        return $rnc;
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

    private function canCreateNewProposal(RelatorioNaoConformidade $rnc): bool
    {
        $latestAction = $rnc->acoesCorretivas->first();

        return ! $latestAction || $latestAction->status === 'rejected';
    }

    private function latestPendingProposal(RelatorioNaoConformidade $rnc): ?RelatorioNaoConformidadeAcaoCorretiva
    {
        return $rnc->acoesCorretivas
            ->first(fn (RelatorioNaoConformidadeAcaoCorretiva $acao): bool => $acao->status === 'pending');
    }

    /**
     * @return array<string, mixed>
     */
    private function formatCorrectiveAction(RelatorioNaoConformidadeAcaoCorretiva $acao, Tenant $tenant, RelatorioNaoConformidade $rnc): array
    {
        return [
            'id' => $acao->id,
            'descricao_proposta' => $acao->descricao_proposta,
            'prazo_execucao_proposto' => $acao->prazo_execucao_proposto,
            'prazo_execucao_proposto_formatted' => $acao->prazo_execucao_proposto?->format('d/m/Y'),
            'attachment_original_name' => $acao->attachment_original_name,
            'attachment_size' => $acao->attachment_size,
            'status' => $acao->status,
            'review_observation' => $acao->review_observation,
            'submitted_at' => $acao->submitted_at,
            'submitted_at_formatted' => $acao->submitted_at?->format('d/m/Y H:i'),
            'reviewed_at' => $acao->reviewed_at,
            'reviewed_at_formatted' => $acao->reviewed_at?->format('d/m/Y H:i'),
            'user' => $acao->user,
            'reviewer' => $acao->reviewer,
            'url' => Storage::disk('public')->url($acao->attachment_path),
            'download_url' => route('tenant.qualidade.rnc.acao-corretiva.download', [$tenant, $rnc, $acao], false),
        ];
    }

    /**
     * @return Collection<int, User>
     */
    private function responsibleUsers(Tenant $tenant, Contract $contract): Collection
    {
        return RelatorioNaoConformidadeResponsavel::query()
            ->where('status', 'active')
            ->where('tenant_id', $tenant->id)
            ->where('contract_id', $contract->id)
            ->with('user:id,name,email')
            ->get()
            ->pluck('user')
            ->filter(fn (?User $user): bool => $user !== null && $this->canAccessContract($user, $tenant, $contract))
            ->unique('id')
            ->values();
    }

    /**
     * @return Collection<int, User>
     */
    private function proposalReviewRecipients(Tenant $tenant, Contract $contract, RelatorioNaoConformidadeAcaoCorretiva $acaoCorretiva): Collection
    {
        return $this->responsibleUsers($tenant, $contract)
            ->push($acaoCorretiva->user)
            ->filter(fn (?User $user): bool => $user !== null && $this->canAccessContract($user, $tenant, $contract))
            ->unique('id')
            ->values();
    }
}
