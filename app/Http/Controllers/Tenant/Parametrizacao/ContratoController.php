<?php

namespace App\Http\Controllers\Tenant\Parametrizacao;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\TipoEmpresa;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ContratoController extends Controller
{
    public function index(Tenant $tenant): Response
    {
        return Inertia::render('Tenant/Parametrizacao/Contratos/Index', [
            'tenant' => $tenant,
            'contracts' => $tenant->contracts()
                ->with(['obra', 'clienteEmpresa', 'construtoraEmpresa', 'gerenciadoraEmpresa'])
                ->orderBy('code')
                ->get(),
            'obras' => $tenant->obras()
                ->orderBy('codigo')
                ->get(['id', 'contract_id', 'codigo', 'nome', 'tipo']),
            'empresas' => $tenant->empresas()
                ->with('tipoEmpresa')
                ->orderBy('nome')
                ->get(),
        ]);
    }

    public function store(Request $request, Tenant $tenant): RedirectResponse
    {
        $clienteTipoId = TipoEmpresa::where('nome', 'cliente')->value('id');
        $construtoraTipoId = TipoEmpresa::where('nome', 'construtora')->value('id');
        $gerenciadoraTipoId = TipoEmpresa::where('nome', 'gerenciadora')->value('id');

        $data = $request->validate([
            'contract_id' => [
                'required',
                Rule::exists('contracts', 'id')->where(fn ($query) => $query->where('tenant_id', $tenant->id)),
            ],
            'obra_id' => [
                'nullable',
                Rule::exists('obras', 'id')->where(fn ($query) => $query
                    ->where('tenant_id', $tenant->id)
                    ->where('contract_id', $request->input('contract_id'))),
            ],
            'cliente_empresa_id' => [
                'nullable',
                Rule::exists('empresas', 'id')->where(fn ($query) => $query
                    ->where('tenant_id', $tenant->id)
                    ->where('contract_id', $request->input('contract_id'))
                    ->where('tipo_empresa_id', $clienteTipoId)),
            ],
            'construtora_empresa_id' => [
                'nullable',
                Rule::exists('empresas', 'id')->where(fn ($query) => $query
                    ->where('tenant_id', $tenant->id)
                    ->where('contract_id', $request->input('contract_id'))
                    ->where('tipo_empresa_id', $construtoraTipoId)),
            ],
            'gerenciadora_empresa_id' => [
                'nullable',
                Rule::exists('empresas', 'id')->where(fn ($query) => $query
                    ->where('tenant_id', $tenant->id)
                    ->where('contract_id', $request->input('contract_id'))
                    ->where('tipo_empresa_id', $gerenciadoraTipoId)),
            ],
        ], [
            'contract_id.required' => 'Selecione o contrato.',
            'obra_id.exists' => 'A obra selecionada não pertence ao contrato.',
            'cliente_empresa_id.exists' => 'A empresa cliente selecionada não pertence ao contrato ou não é do tipo cliente.',
            'construtora_empresa_id.exists' => 'A construtora selecionada não pertence ao contrato ou não é do tipo construtora.',
            'gerenciadora_empresa_id.exists' => 'A gerenciadora selecionada nao pertence ao contrato ou nao e do tipo gerenciadora.',
        ]);

        $contract = $tenant->contracts()->findOrFail($data['contract_id']);
        $contract->update([
            'obra_id' => $data['obra_id'] ?? null,
            'cliente_empresa_id' => $data['cliente_empresa_id'] ?? null,
            'construtora_empresa_id' => $data['construtora_empresa_id'] ?? null,
            'fiscalizadora_empresa_id' => $data['gerenciadora_empresa_id'] ?? null,
            'client_company_name' => $this->empresaNome($tenant, $data['cliente_empresa_id'] ?? null),
            'contractor_company_name' => $this->empresaNome($tenant, $data['construtora_empresa_id'] ?? null),
            'name' => $this->obraNome($tenant, $data['obra_id'] ?? null) ?? $contract->name,
        ]);

        return back()->with('success', 'Parametrização do contrato atualizada.');
    }

    private function empresaNome(Tenant $tenant, ?int $empresaId): ?string
    {
        if (! $empresaId) {
            return null;
        }

        return $tenant->empresas()->whereKey($empresaId)->value('nome');
    }

    private function obraNome(Tenant $tenant, ?int $obraId): ?string
    {
        if (! $obraId) {
            return null;
        }

        return $tenant->obras()->whereKey($obraId)->value('nome');
    }
}
