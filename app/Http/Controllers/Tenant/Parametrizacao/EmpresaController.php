<?php

namespace App\Http\Controllers\Tenant\Parametrizacao;

use App\Http\Controllers\Controller;
use App\Models\Empresa;
use App\Models\RelatorioNaoConformidade;
use App\Models\Tenant;
use App\Models\TipoEmpresa;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class EmpresaController extends Controller
{
    public function index(Tenant $tenant): Response
    {
        return Inertia::render('Tenant/Parametrizacao/Empresas/Index', [
            'tenant' => $tenant,
            'empresas' => $tenant->empresas()
                ->with(['tipoEmpresa', 'contract'])
                ->latest()
                ->get(),
            'contracts' => $tenant->contracts()
                ->orderBy('code')
                ->get(['id', 'code', 'name']),
            'tiposEmpresa' => TipoEmpresa::query()
                ->orderBy('nome')
                ->get(['id', 'nome']),
        ]);
    }

    public function store(Request $request, Tenant $tenant): RedirectResponse
    {
        $data = $this->validatedEmpresaData($request, $tenant);

        $tenant->empresas()->create($data);

        return back()->with('success', 'Empresa cadastrada com sucesso.');
    }

    public function update(Request $request, Tenant $tenant, Empresa $empresa): RedirectResponse
    {
        abort_unless((int) $empresa->tenant_id === (int) $tenant->id, 404);

        $data = $this->validatedEmpresaData($request, $tenant, $empresa);

        if (isset($data['logo_path']) && $empresa->logo_path) {
            Storage::disk('public')->delete($empresa->logo_path);
        }

        $empresa->update($data);

        return back()->with('success', 'Empresa atualizada com sucesso.');
    }

    public function destroy(Tenant $tenant, Empresa $empresa): RedirectResponse
    {
        abort_unless((int) $empresa->tenant_id === (int) $tenant->id, 404);

        $hasRncHistory = RelatorioNaoConformidade::query()
            ->where('tenant_id', $tenant->id)
            ->where(function ($query) use ($empresa): void {
                $query
                    ->where('contratante_empresa_id', $empresa->id)
                    ->orWhere('contratada_empresa_id', $empresa->id);
            })
            ->exists();

        if ($hasRncHistory) {
            return back()->with('error', 'Esta empresa possui historico de RNC e nao pode ser deletada.');
        }

        $empresa->delete();

        return back()->with('success', 'Empresa deletada com sucesso. O registro foi mantido no historico.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedEmpresaData(Request $request, Tenant $tenant, ?Empresa $empresa = null): array
    {
        $request->merge([
            'cnpj' => $this->normalizeCnpj((string) $request->input('cnpj', '')),
            'sigla' => mb_strtoupper((string) $request->input('sigla', '')),
        ]);

        $uniqueCnpj = Rule::unique('empresas', 'cnpj')
            ->where(fn ($query) => $query
                ->where('tenant_id', $tenant->id)
                ->where('contract_id', $request->input('contract_id')));

        if ($empresa) {
            $uniqueCnpj->ignore($empresa->id);
        }

        $data = $request->validate([
            'nome' => ['required', 'string', 'max:255'],
            'contract_id' => [
                'required',
                Rule::exists('contracts', 'id')->where(fn ($query) => $query->where('tenant_id', $tenant->id)),
            ],
            'cnpj' => [
                'required',
                'string',
                'size:18',
                'regex:/^\d{2}\.\d{3}\.\d{3}\/\d{4}-\d{2}$/',
                $uniqueCnpj,
            ],
            'sigla' => ['required', 'string', 'max:20'],
            'tipo_empresa_id' => ['required', Rule::exists('tipos_empresa', 'id')],
            'logo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
        ], [
            'cnpj.size' => 'Informe um CNPJ com 14 digitos.',
            'cnpj.regex' => 'Informe um CNPJ no formato 00.000.000/0000-00.',
            'contract_id.required' => 'Selecione o contrato.',
            'contract_id.exists' => 'O contrato selecionado nao esta disponivel para este tenant.',
            'logo.image' => 'Envie a logo em formato de imagem.',
            'logo.mimes' => 'A logo deve ser JPG, PNG ou WebP.',
            'logo.max' => 'A logo pode ter no maximo 4 MB.',
        ]);

        $logo = $data['logo'] ?? null;
        unset($data['logo']);

        if ($logo) {
            $data['logo_path'] = $logo->store("tenant-{$tenant->id}/empresas/logos", 'public');
        }

        return $data;
    }

    private function normalizeCnpj(string $value): string
    {
        $digits = preg_replace('/\D/', '', $value) ?? '';

        if (strlen($digits) !== 14) {
            return $digits;
        }

        return sprintf(
            '%s.%s.%s/%s-%s',
            substr($digits, 0, 2),
            substr($digits, 2, 3),
            substr($digits, 5, 3),
            substr($digits, 8, 4),
            substr($digits, 12, 2),
        );
    }
}
