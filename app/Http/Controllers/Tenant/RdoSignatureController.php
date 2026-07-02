<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\RdoDiario;
use App\Models\RdoSignatureRequest;
use App\Models\Tenant;
use App\Services\RdoSignatureService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class RdoSignatureController extends Controller
{
    public function store(Tenant $tenant, RdoDiario $rdo, RdoSignatureService $signatureService): RedirectResponse
    {
        abort_unless((int) $rdo->tenant_id === (int) $tenant->id, 404);
        $rdo->loadMissing('configuracao');

        abort_if(
            ! (bool) ($rdo->configuracao?->digital_signature_enabled ?? true),
            422,
            'Este RDO estÃ¡ configurado para upload manual do documento assinado.'
        );

        $signatureService->createAndSend($tenant, $rdo, request()->user());

        return back()->with('success', 'RDO enviado para assinatura.');
    }

    public function uploadManual(Request $request, Tenant $tenant, RdoDiario $rdo): RedirectResponse
    {
        abort_unless((int) $rdo->tenant_id === (int) $tenant->id, 404);
        $rdo->loadMissing('configuracao');

        abort_unless($rdo->status === 'arquivado', 422, 'Somente RDO aprovado e arquivado pode receber documento assinado.');
        abort_if(
            (bool) ($rdo->configuracao?->digital_signature_enabled ?? true),
            422,
            'Este RDO utiliza assinatura digital. Use o envio para assinatura.'
        );

        $data = $request->validate([
            'signed_pdf' => ['required', 'file', 'mimes:pdf', 'max:20480'],
        ]);

        $file = $data['signed_pdf'];
        $fileName = sprintf(
            'rdo-%s-%s-assinado-manual-%s.pdf',
            Str::slug($rdo->code),
            $rdo->reference_date?->format('Ymd') ?? now()->format('Ymd'),
            Str::lower(Str::random(8))
        );

        $path = $file->storeAs("tenant-{$tenant->id}/rdo/{$rdo->id}/assinaturas", $fileName, 'public');

        RdoSignatureRequest::create([
            'tenant_id' => $tenant->id,
            'rdo_diario_id' => $rdo->id,
            'requested_by_id' => $request->user()?->id,
            'provider' => 'manual',
            'status' => 'completed',
            'title' => "RDO {$rdo->code} - documento assinado",
            'signed_pdf_path' => $path,
            'request_payload' => [
                'manual_upload' => true,
                'original_name' => $file->getClientOriginalName(),
                'size' => $file->getSize(),
            ],
            'sent_at' => now(),
            'completed_at' => now(),
        ]);

        return back()->with('success', 'Documento assinado enviado manualmente.');
    }

    public function downloadUnsigned(Tenant $tenant, RdoDiario $rdo, RdoSignatureRequest $signature): Response
    {
        return $this->download($tenant, $rdo, $signature, 'unsigned_pdf_path', 'rdo-para-assinatura.pdf');
    }

    public function refresh(Tenant $tenant, RdoDiario $rdo, RdoSignatureRequest $signature, RdoSignatureService $signatureService): RedirectResponse
    {
        abort_unless((int) $rdo->tenant_id === (int) $tenant->id, 404);
        abort_unless((int) $signature->tenant_id === (int) $tenant->id && (int) $signature->rdo_diario_id === (int) $rdo->id, 404);

        $signature = $signatureService->refreshFromProvider($signature);

        return back()->with(
            $signature->signed_pdf_path ? 'success' : 'info',
            $signature->signed_pdf_path
                ? 'Assinatura atualizada. O PDF assinado já está disponível para download.'
                : 'Assinatura atualizada. O PDF assinado ainda não foi disponibilizado pelo provedor.',
        );
    }

    public function downloadSigned(Tenant $tenant, RdoDiario $rdo, RdoSignatureRequest $signature): Response
    {
        abort_if($signature->provider === 'opensign' && ! $this->allOpenSignSignersCompleted($signature), 404);

        return $this->download($tenant, $rdo, $signature, 'signed_pdf_path', 'rdo-assinado.pdf');
    }

    private function allOpenSignSignersCompleted(RdoSignatureRequest $signature): bool
    {
        $signature->loadMissing('signers');

        return $signature->signers->isNotEmpty()
            && $signature->signers->every(function ($signer): bool {
                if ($signer->status !== 'completed') {
                    return false;
                }

                $payload = $signer->provider_payload ?? [];
                $status = data_get($payload, 'status')
                    ?? data_get($payload, 'Status')
                    ?? data_get($payload, 'signerStatus')
                    ?? data_get($payload, 'signer_status')
                    ?? data_get($payload, 'raw.status')
                    ?? data_get($payload, 'raw.Status')
                    ?? data_get($payload, 'raw.signerStatus')
                    ?? data_get($payload, 'raw.signer_status');

                if ($status) {
                    $normalized = str($status)->lower()->replace([' ', '-'], '_')->toString();

                    return in_array($normalized, ['completed', 'complete', 'signed', 'document_signed', 'finished'], true);
                }

                $signedAt = data_get($payload, 'signedAt')
                    ?? data_get($payload, 'SignedAt')
                    ?? data_get($payload, 'completedAt')
                    ?? data_get($payload, 'CompletedAt')
                    ?? data_get($payload, 'raw.signedAt')
                    ?? data_get($payload, 'raw.completedAt');

                if ($signedAt) {
                    return true;
                }

                $signed = data_get($payload, 'signed')
                    ?? data_get($payload, 'Signed')
                    ?? data_get($payload, 'isSigned')
                    ?? data_get($payload, 'IsSigned')
                    ?? data_get($payload, 'completed')
                    ?? data_get($payload, 'Completed')
                    ?? data_get($payload, 'isCompleted')
                    ?? data_get($payload, 'IsCompleted')
                    ?? data_get($payload, 'raw.signed')
                    ?? data_get($payload, 'raw.isSigned')
                    ?? data_get($payload, 'raw.completed')
                    ?? data_get($payload, 'raw.isCompleted');

                return in_array($signed, [true, 1, '1', 'true', 'yes', 'sim'], true);
            });
    }

    private function download(Tenant $tenant, RdoDiario $rdo, RdoSignatureRequest $signature, string $column, string $fileName): Response
    {
        abort_unless((int) $rdo->tenant_id === (int) $tenant->id, 404);
        abort_unless((int) $signature->tenant_id === (int) $tenant->id && (int) $signature->rdo_diario_id === (int) $rdo->id, 404);

        $path = $signature->{$column};
        abort_unless($path && Storage::disk('public')->exists($path), 404);

        return response(Storage::disk('public')->get($path), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$fileName.'"',
        ]);
    }
}
