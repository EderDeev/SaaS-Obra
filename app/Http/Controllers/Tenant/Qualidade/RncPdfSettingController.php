<?php

namespace App\Http\Controllers\Tenant\Qualidade;

use App\Http\Controllers\Controller;
use App\Models\{Contract, RncPdfSetting, RncSignatureSetting, Tenant};
use App\Services\{RncPdfPreview, RncPdfRenderer};
use App\Support\{RncPdfTemplates, RncPermissions};
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class RncPdfSettingController extends Controller
{
    public function index(Request $request, Tenant $tenant)
    {
        abort_unless(RncPermissions::canAny($request->user(), $tenant, RncPermissions::RESPONSIBLES), 403);
        $ids = RncPermissions::contractIdsFor($request->user(), $tenant, RncPermissions::RESPONSIBLES);
        $contracts = $tenant->contracts()->when($ids !== null, fn ($q) => $q->whereIn('id', $ids))
            ->orderBy('code')->get(['id', 'code', 'name']);
        $settings = RncPdfSetting::where('tenant_id', $tenant->id)->whereIn('contract_id', $contracts->pluck('id'))->get()
            ->mapWithKeys(fn ($setting) => [$setting->contract_id => RncPdfTemplates::configuration($setting->layout, $setting->options ?? [])]);

        return Inertia::render('Tenant/Qualidade/RelatorioNaoConformidade/PdfSettings', [
            'tenant' => $tenant, 'contracts' => $contracts, 'settings' => $settings, 'templates' => RncPdfTemplates::catalog(),
            'defaults' => collect(array_keys(RncPdfTemplates::VIEWS))->mapWithKeys(fn ($layout) => [$layout => RncPdfTemplates::defaults($layout)]),
        ]);
    }

    private function validated(Request $request, Tenant $tenant): array
    {
        abort_unless(RncPermissions::canAny($request->user(), $tenant, RncPermissions::RESPONSIBLES), 403);
        $data = $request->validate([
            'contract_id' => ['required', 'integer', Rule::exists('contracts', 'id')->where('tenant_id', $tenant->id)],
            'layout' => ['required', Rule::in(array_keys(RncPdfTemplates::VIEWS))],
            'options' => ['required', 'array:title,color,left_logo,right_logo,contract_notes,work_type,pavement_type'],
            'options.title' => ['nullable', 'string', 'max:180'],
            'options.color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'options.left_logo' => ['required', Rule::in(['contratante', 'contratada', 'fiscalizadora', 'none'])],
            'options.right_logo' => ['required', Rule::in(['contratante', 'contratada', 'fiscalizadora', 'none'])],
            'options.contract_notes' => ['nullable', 'string', 'max:10000'],
            'options.work_type' => ['nullable', Rule::in(['Pista Nova', 'Pista Existente'])],
            'options.pavement_type' => ['nullable', Rule::in(['Rígido', 'Flexível'])],
        ]);
        $contract = $tenant->contracts()->findOrFail($data['contract_id']);
        abort_unless(RncPermissions::can($request->user(), $tenant, RncPermissions::RESPONSIBLES, $contract), 403);

        return [$contract, RncPdfTemplates::configuration($data['layout'], $data['options'])];
    }

    public function store(Request $request, Tenant $tenant)
    {
        [$contract, $configuration] = $this->validated($request, $tenant);
        RncPdfSetting::updateOrCreate(['tenant_id' => $tenant->id, 'contract_id' => $contract->id],
            [...$configuration, 'updated_by_id' => $request->user()->id]);

        return back()->with('success', 'Modelo de PDF da RNC salvo para este contrato.');
    }

    public function preview(Request $request, Tenant $tenant, RncPdfRenderer $renderer, RncPdfPreview $preview)
    {
        [$contract, $configuration] = $this->validated($request, $tenant);
        $signers = collect(RncSignatureSetting::ROLES)->map(fn ($label, $role) => [
            'role' => $role, 'name' => 'Responsável da '.$label, 'email' => $role.'@example.test',
        ])->values()->all();
        $response = $renderer->render($tenant, $preview->make($tenant, $contract), 'evidence', $signers, [...$configuration, 'preview' => true])->stream('exemplo-modelo-rnc.pdf');
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
