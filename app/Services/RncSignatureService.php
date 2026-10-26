<?php

namespace App\Services;

use App\Models\RelatorioNaoConformidade;
use App\Models\RdoSignatureRequest;
use App\Models\RncSignatureSetting;
use App\Models\Tenant;
use App\Models\User;
use App\Support\RncPdfTemplates;
use App\Services\Signatures\OpenSignSignatureProvider;
use App\Services\Storage\StoredFileMaterializer;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class RncSignatureService
{
    public function __construct(private readonly RncPdfRenderer $renderer, private readonly StoredFileMaterializer $materializer) {}

    public function configuredSigners(Tenant $tenant, RelatorioNaoConformidade $rnc): array
    {
        $settings = RncSignatureSetting::where('tenant_id', $tenant->id)->where('contract_id', $rnc->contract_id)->first();
        $signers = [];
        foreach (RncSignatureSetting::ROLES as $role => $label) {
            $id = $settings?->signers[$role] ?? null;
            if (!$id) continue;
            $user = User::find($id);
            if (!$user || !self::eligibleUser($tenant, $rnc->contract_id, $user)) {
                throw ValidationException::withMessages(['signatures' => "O responsável de assinatura da parte {$label} não possui acesso ativo ao contrato. Revise os responsáveis."]);
            }
            $signers[] = ['role' => $role, 'user_id' => $user->id, 'name' => $user->name, 'email' => Str::lower(trim($user->email))];
        }
        return $signers;
    }

    public static function eligibleUser(Tenant $tenant, int $contractId, User $user): bool
    {
        if (!filter_var($user->email, FILTER_VALIDATE_EMAIL) || !$tenant->memberships()->where('user_id', $user->id)->where('status', 'active')->exists()) return false;
        return in_array($user->tenantRole($tenant), ['tenant_owner', 'tenant_admin'], true)
            || $tenant->contracts()->whereKey($contractId)->whereHas('participants', fn ($q) => $q->where('user_id', $user->id)->where('status', 'active'))->exists();
    }

    public function statusData(Tenant $tenant, RelatorioNaoConformidade $rnc): array
    {
        $request = $rnc->signatureRequests()->with('signers')->first();
        $error = null;
        $configured = [];
        try { $configured = $this->configuredSigners($tenant, $rnc); }
        catch (ValidationException $exception) { $error = collect($exception->errors())->flatten()->first(); }
        return [
            'configured' => $configured, 'configuration_error' => $error,
            'integration_ready' => config('signatures.driver') === 'opensign' && (bool)config('signatures.opensign.base_url') && (bool)config('signatures.opensign.api_key'),
            'request' => $request ? ['id' => $request->id, 'status' => $request->status, 'sent_at' => $request->sent_at, 'completed_at' => $request->completed_at,
                'error_message' => $request->error_message, 'has_original' => (bool)$request->unsigned_pdf_path,
                'signers' => $request->signers->map(fn ($signer) => $signer->only(['name', 'email', 'role', 'status', 'signed_at']))->values()] : null,
            'has_signed_pdf' => (bool)($rnc->finalized_at && $request?->status === 'completed' && $request->signed_pdf_path && Storage::disk($request->artifactDisk())->exists($request->signed_pdf_path)),
        ];
    }

    public function createAndSend(Tenant $tenant, RelatorioNaoConformidade $rnc, User $actor): RdoSignatureRequest
    {
        if (config('signatures.driver') !== 'opensign' || !config('signatures.opensign.base_url') || !config('signatures.opensign.api_key')) {
            throw ValidationException::withMessages(['signatures' => 'A integração OpenSign precisa estar configurada para enviar a RNC por e-mail para assinatura.']);
        }
        $request = DB::transaction(function () use ($tenant, $rnc, $actor) {
            $rnc = RelatorioNaoConformidade::where('tenant_id', $tenant->id)->whereKey($rnc->id)->lockForUpdate()->firstOrFail();
            app(RncPdfLogoSnapshots::class)->freezeOne($tenant, $rnc);
            $evidence = $rnc->evidencias()->latest('id')->first();
            if (!$evidence) throw ValidationException::withMessages(['signatures' => 'Registre as evidências antes de enviar a RNC para assinatura.']);
            if ($rnc->signatureRequests()->whereIn('status', ['draft', 'sent', 'pending', 'completed'])->exists()) {
                throw ValidationException::withMessages(['signatures' => 'Esta RNC já possui uma solicitação de assinatura em andamento ou concluída.']);
            }
            $signers = $this->configuredSigners($tenant, $rnc);
            if (!$signers) throw ValidationException::withMessages(['signatures' => 'Escolha pelo menos uma parte e seu responsável para assinatura em Responsáveis.']);
            if (count(array_unique(array_column($signers, 'email'))) !== count($signers)) throw ValidationException::withMessages(['signatures' => 'Cada parte deve ter um responsável com e-mail diferente. Revise os responsáveis pela assinatura.']);
            $request = RdoSignatureRequest::create(['tenant_id' => $tenant->id, 'relatorio_nao_conformidade_id' => $rnc->id, 'requested_by_id' => $actor->id,
                'provider' => 'opensign', 'status' => 'draft', 'title' => "RNC {$rnc->formatted_number} - {$rnc->assunto}",
                'request_payload' => ['signers' => $signers, 'evidence_id' => $evidence->id,
                    'previous_closure' => $rnc->finalized_at ? ['at' => $rnc->finalized_at->toISOString(), 'by' => $rnc->finalized_by_id] : null]]);
            foreach ($signers as $signer) $request->signers()->create(['tenant_id' => $tenant->id, ...$signer, 'status' => 'pending']);
            $rnc->update(['status' => 'em_assinatura', 'finalized_at' => null, 'finalized_by_id' => null]);
            return $request->load(['tenant', 'rnc', 'signers']);
        });
        try {
            $rnc = $request->rnc;
            $signers = $request->request_payload['signers'];
            $template = RncPdfTemplates::forContract($tenant, $rnc->contract_id);
            $template = app(RncPdfLogoSnapshots::class)->template($tenant, $rnc, $template);
            $pdf = $this->renderer->render($tenant, $rnc->fresh(), 'evidence', $signers, $template);
            $bytes = $pdf->output();
            $page = $pdf->getDomPDF()->getCanvas()->get_page_count();
            $path = "tenants/{$tenant->id}/rnc/{$rnc->id}/assinaturas/{$request->id}-original.pdf";
            Storage::disk('local')->put($path, $bytes);
            $request->update(['unsigned_pdf_path' => $path, 'request_payload' => [...$request->request_payload, 'signature_page' => $page, 'pdf_template' => $template, 'unsigned_sha256' => hash('sha256', $bytes)]]);
            $response = $this->materializer->withLocalPath('local', $path, fn ($local) => app(OpenSignSignatureProvider::class)->createRequest($request->fresh(['rnc', 'signers']), $local));
            if (empty($response['provider_document_id']) && empty($response['provider_request_id'])) throw new \RuntimeException('OpenSign did not return a document identifier.');
            $request->update(['status' => 'sent', 'provider_request_id' => $response['provider_request_id'] ?? null, 'provider_document_id' => $response['provider_document_id'] ?? null,
                'provider_payload' => $response['raw'] ?? $response, 'sent_at' => now()]);
            app(RdoSignatureService::class)->syncProviderSigners($request->fresh('signers'), $response['signers'] ?? []);
        } catch (\Throwable $error) {
            $uncertain = $error instanceof ConnectionException;
            $message = $uncertain ? 'O OpenSign não confirmou o envio. Aguarde a confirmação antes de tentar novamente.' : 'Não foi possível enviar o documento para assinatura. Verifique a integração e tente novamente.';
            $request->update(['status' => $uncertain ? 'pending' : 'failed', 'error_message' => $message]);
            report($error);
            throw ValidationException::withMessages(['signatures' => $message]);
        }
        return $request->fresh('signers');
    }
}
