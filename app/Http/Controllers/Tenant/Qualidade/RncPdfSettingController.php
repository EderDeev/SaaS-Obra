<?php

namespace App\Http\Controllers\Tenant\Qualidade;

use App\Http\Controllers\Controller;
use App\Models\{Contract, RncPdfSetting, RncSignatureSetting, Tenant};
use App\Services\{RncPdfLogoSnapshots, RncPdfPreview, RncPdfRenderer, RncSignatureService};
use App\Support\{RncModelFields, RncPdfTemplates, RncPermissions};
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class RncPdfSettingController extends Controller
{
    public function index(Request $request, Tenant $tenant)
    {
        abort_unless(RncPermissions::canAny($request->user(), $tenant, RncPermissions::RESPONSIBLES), 403);
        $ids = RncPermissions::contractIdsFor($request->user(), $tenant, RncPermissions::RESPONSIBLES);
        $contracts = $tenant->contracts()->when($ids !== null, fn ($q) => $q->whereIn('id', $ids))
            ->orderBy('code')->get(['id', 'code', 'name']);
        $records = RncPdfSetting::where('tenant_id', $tenant->id)->whereIn('contract_id', $contracts->pluck('id'))->get();
        $settings = $records->mapWithKeys(function ($setting) {
            $config = RncPdfTemplates::configuration($setting->layout, $setting->options ?? [], $setting->field_definitions);
            $config['options'] = Arr::only($config['options'], RncPdfTemplates::EDITABLE_OPTIONS);
            return [$setting->contract_id => $config];
        });
        $logos = $records->mapWithKeys(function ($setting) use ($tenant) {
            $sides = [];
            foreach (['left', 'right'] as $side) {
                $path = $setting->options[$side.'_logo_path'] ?? '';
                $sides[$side] = RncPdfTemplates::validLogoPath($tenant, $setting->contract_id, $path) && Storage::disk('local')->exists($path)
                    ? ['name' => $setting->options[$side.'_logo_name'] ?? 'Logo', 'url' => route('tenant.qualidade.rnc.settings.logo', ['tenant' => $tenant, 'contract' => $setting->contract_id, 'side' => $side, 'v' => substr(hash('sha256', $path), 0, 12)])]
                    : null;
            }
            return [$setting->contract_id => $sides];
        });

        return Inertia::render('Tenant/Qualidade/RelatorioNaoConformidade/PdfSettings', [
            'tenant' => $tenant, 'contracts' => $contracts, 'settings' => $settings, 'logos' => $logos, 'templates' => RncPdfTemplates::catalog(),
            'defaults' => collect(array_keys(RncPdfTemplates::VIEWS))->mapWithKeys(fn ($layout) => [$layout => RncPdfTemplates::defaults($layout)]),
            'defaultFields' => collect(array_keys(RncPdfTemplates::VIEWS))->mapWithKeys(fn ($layout) => [$layout => RncModelFields::defaults($layout)]),
        ]);
    }

    private function validated(Request $request, Tenant $tenant): array
    {
        abort_unless(RncPermissions::canAny($request->user(), $tenant, RncPermissions::RESPONSIBLES), 403);
        $data = $request->validate([
            'contract_id' => ['required', 'integer', Rule::exists('contracts', 'id')->where('tenant_id', $tenant->id)],
            'layout' => ['required', Rule::in(array_keys(RncPdfTemplates::VIEWS))],
            'options' => ['required', 'array:title,color,contract_notes,work_type,pavement_type,left_logo,right_logo'],
            'options.title' => ['nullable', 'string', 'max:180'],
            'options.color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'options.contract_notes' => ['nullable', 'string', 'max:10000'],
            'options.work_type' => ['nullable', Rule::in(['Pista Nova', 'Pista Existente'])],
            'options.pavement_type' => ['nullable', Rule::in(['Rígido', 'Flexível'])],
            'options.left_logo' => ['sometimes', Rule::in(['contratante', 'contratada', 'fiscalizadora', 'none'])],
            'options.right_logo' => ['sometimes', Rule::in(['contratante', 'contratada', 'fiscalizadora', 'none'])],
            'left_logo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096', 'dimensions:max_width=4096,max_height=4096'],
            'right_logo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096', 'dimensions:max_width=4096,max_height=4096'],
            'remove_left_logo' => ['sometimes', 'boolean'],
            'remove_right_logo' => ['sometimes', 'boolean'],
            'fields_json' => ['sometimes', 'string', 'json', 'max:50000'],
        ]);
        $contract = $tenant->contracts()->findOrFail($data['contract_id']);
        abort_unless(RncPermissions::can($request->user(), $tenant, RncPermissions::RESPONSIBLES, $contract), 403);

        $existing = RncPdfTemplates::forContract($tenant, $contract->id);
        $options = [...Arr::only($existing['options'], RncPdfTemplates::LOGO_OPTIONS), ...$data['options']];
        foreach (['left', 'right'] as $side) {
            if ($request->boolean('remove_'.$side.'_logo')) {
                $options[$side.'_logo_path'] = '';
                $options[$side.'_logo_name'] = '';
            }
        }
        $fields = $request->has('fields_json')
            ? RncModelFields::validateDefinitions($data['layout'], json_decode($data['fields_json'], true))
            : ($data['layout'] === $existing['layout'] ? $existing['field_definitions'] : RncModelFields::defaults($data['layout']));
        return [$contract, RncPdfTemplates::configuration($data['layout'], $options, $fields)];
    }

    public function store(Request $request, Tenant $tenant)
    {
        [$contract, $configuration] = $this->validated($request, $tenant);
        $created = [];
        $uploaded = [];
        try {
            foreach (['left', 'right'] as $side) {
                $file = $request->file($side.'_logo');
                if (! $file) continue;
                $path = $file->store(RncPdfTemplates::logoDirectory($tenant, $contract->id), 'local');
                if (! $path) throw new \RuntimeException('Não foi possível armazenar a logo.');
                $created[] = $path;
                $uploaded[$side.'_logo_path'] = $path;
                $uploaded[$side.'_logo_name'] = $file->getClientOriginalName();
            }
            DB::transaction(function () use ($tenant, $contract, $request, $configuration, $uploaded) {
                // Creation uses the same tenant lock, so every RNC gets one complete logo version.
                Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
                app(RncPdfLogoSnapshots::class)->freezeContract($tenant, $contract->id);
                app(\App\Services\RncDocumentSnapshots::class)->freezeContract($tenant, $contract->id);
                $current = RncPdfTemplates::forContract($tenant, $contract->id);
                $options = [...Arr::only($configuration['options'], RncPdfTemplates::EDITABLE_OPTIONS), ...Arr::only($current['options'], RncPdfTemplates::LOGO_OPTIONS)];
                foreach (['left', 'right'] as $side) {
                    if ($request->boolean('remove_'.$side.'_logo')) {
                        $options[$side.'_logo_path'] = '';
                        $options[$side.'_logo_name'] = '';
                    }
                }
                // Old files belong to historical RNCs and must never be replaced or deleted.
                RncPdfSetting::updateOrCreate(['tenant_id' => $tenant->id, 'contract_id' => $contract->id],
                    [...RncPdfTemplates::configuration($configuration['layout'], [...$options, ...$uploaded], $configuration['field_definitions']), 'updated_by_id' => $request->user()->id]);
            });
        } catch (\Throwable $error) {
            Storage::disk('local')->delete($created);
            throw $error;
        }

        return back()->with('success', 'Modelo de PDF da RNC salvo para este contrato.');
    }

    public function preview(Request $request, Tenant $tenant, RncPdfRenderer $renderer, RncPdfPreview $preview, RncSignatureService $signatures)
    {
        [$contract, $configuration] = $this->validated($request, $tenant);
        $rnc = $preview->make($tenant, $contract, $request->user());
        try { $signers = $signatures->configuredSigners($tenant, $rnc); }
        catch (ValidationException) { $signers = []; }
        $signers = $signers ?: collect(RncSignatureSetting::ROLES)->map(fn ($label, $role) => [
            'role' => $role, 'name' => 'Responsável pela '.$label, 'email' => '',
        ])->values()->all();
        $response = $renderer->render($tenant, $rnc, 'evidence', $signers, [...$configuration, 'preview' => true], [
            'left' => $request->file('left_logo'), 'right' => $request->file('right_logo'),
        ])->stream('modelo-rnc.pdf');
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    public function logo(Request $request, Tenant $tenant, Contract $contract, string $side)
    {
        abort_unless((int) $contract->tenant_id === (int) $tenant->id, 404);
        abort_unless(RncPermissions::can($request->user(), $tenant, RncPermissions::RESPONSIBLES, $contract), 403);
        $config = RncPdfTemplates::forContract($tenant, $contract->id);
        $path = $config['options'][$side.'_logo_path'] ?? '';
        abort_unless(RncPdfTemplates::validLogoPath($tenant, $contract->id, $path) && Storage::disk('local')->exists($path), 404);
        return response()->file(Storage::disk('local')->path($path), [
            'Content-Type' => Storage::disk('local')->mimeType($path), 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
