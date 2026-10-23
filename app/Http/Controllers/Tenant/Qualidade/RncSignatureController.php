<?php

namespace App\Http\Controllers\Tenant\Qualidade;

use App\Http\Controllers\Controller;
use App\Models\RelatorioNaoConformidade;
use App\Models\RdoSignatureRequest;
use App\Models\Tenant;
use App\Services\RncSignatureService;
use App\Services\RdoSignatureService;
use App\Support\RncPermissions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class RncSignatureController extends Controller
{
    private function authorizeRnc(Request $request, Tenant $tenant, RelatorioNaoConformidade $rnc, string $permission): void
    {
        abort_unless((int) $rnc->tenant_id === (int) $tenant->id, 404);
        abort_unless(RncPermissions::can($request->user(), $tenant, $permission, $rnc->contract), 403);
    }

    public function store(Request $request, Tenant $tenant, RelatorioNaoConformidade $rnc, RncSignatureService $service)
    {
        $this->authorizeRnc($request, $tenant, $rnc, RncPermissions::EVIDENCE);
        $service->createAndSend($tenant, $rnc, $request->user());
        return back()->with('success', 'Documento enviado pelo OpenSign para assinatura das partes por e-mail.');
    }

    public function refresh(Request $request, Tenant $tenant, RelatorioNaoConformidade $rnc, RdoSignatureRequest $signature, RdoSignatureService $service)
    {
        $this->authorizeRnc($request, $tenant, $rnc, RncPermissions::EVIDENCE);
        abort_unless((int) $signature->tenant_id === (int) $tenant->id && (int) $signature->relatorio_nao_conformidade_id === (int) $rnc->id, 404);
        $service->refreshFromProvider($signature);
        return back()->with('success', 'Situação das assinaturas atualizada.');
    }

    public function unsigned(Request $request, Tenant $tenant, RelatorioNaoConformidade $rnc, RdoSignatureRequest $signature)
    {
        $this->authorizeRnc($request, $tenant, $rnc, RncPermissions::VIEW);
        abort_unless((int) $signature->tenant_id === (int) $tenant->id && (int) $signature->relatorio_nao_conformidade_id === (int) $rnc->id, 404);
        abort_unless($signature->unsigned_pdf_path && Storage::disk($signature->artifactDisk())->exists($signature->unsigned_pdf_path), 404);
        return Storage::disk($signature->artifactDisk())->download($signature->unsigned_pdf_path, "RNC-{$rnc->formatted_number}-enviado-para-assinatura.pdf", ['Content-Type' => 'application/pdf', 'Cache-Control' => 'private, no-store']);
    }
}
