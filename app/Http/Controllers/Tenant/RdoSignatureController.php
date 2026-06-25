<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\RdoDiario;
use App\Models\RdoSignatureRequest;
use App\Models\Tenant;
use App\Services\RdoSignatureService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;

class RdoSignatureController extends Controller
{
    public function store(Tenant $tenant, RdoDiario $rdo, RdoSignatureService $signatureService): RedirectResponse
    {
        abort_unless((int) $rdo->tenant_id === (int) $tenant->id, 404);

        $signatureService->createAndSend($tenant, $rdo, request()->user());

        return back()->with('success', 'RDO enviado para assinatura.');
    }

    public function downloadUnsigned(Tenant $tenant, RdoDiario $rdo, RdoSignatureRequest $signature): Response
    {
        return $this->download($tenant, $rdo, $signature, 'unsigned_pdf_path', 'rdo-para-assinatura.pdf');
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
