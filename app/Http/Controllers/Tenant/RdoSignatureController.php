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
        return $this->download($tenant, $rdo, $signature, 'signed_pdf_path', 'rdo-assinado.pdf');
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
