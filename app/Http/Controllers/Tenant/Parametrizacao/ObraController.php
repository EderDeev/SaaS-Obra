<?php

namespace App\Http\Controllers\Tenant\Parametrizacao;

use App\Http\Controllers\Controller;
use App\Models\Contract;
use App\Models\Obra;
use App\Models\RelatorioNaoConformidade;
use App\Models\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ObraController extends Controller
{
    public function index(Tenant $tenant): Response
    {
        return Inertia::render('Tenant/Parametrizacao/Obras/Index', [
            'tenant' => $tenant,
            'obras' => $tenant->obras()
                ->with(['obraPai', 'contract'])
                ->latest()
                ->get(),
            'contracts' => $tenant->contracts()
                ->orderBy('code')
                ->get(['id', 'code', 'name']),
            'obrasPai' => $tenant->obras()
                ->where('tipo', 'pai')
                ->orderBy('nome')
                ->get(['id', 'contract_id', 'nome', 'codigo']),
        ]);
    }

    public function store(Request $request, Tenant $tenant): RedirectResponse
    {
        $tenant->obras()->create($this->validatedObraData($request, $tenant));

        return back()->with('success', 'Obra cadastrada com sucesso.');
    }

    public function update(Request $request, Tenant $tenant, Obra $obra): RedirectResponse
    {
        abort_unless((int) $obra->tenant_id === (int) $tenant->id, 404);

        $data = $this->validatedObraData($request, $tenant, $obra);

        if ((int) ($data['obra_pai_id'] ?? 0) === (int) $obra->id) {
            throw ValidationException::withMessages([
                'obra_pai_id' => 'Uma obra nao pode ser vinculada a ela mesma.',
            ]);
        }

        if ($data['tipo'] === 'filha' && $obra->obrasFilhas()->exists()) {
            throw ValidationException::withMessages([
                'tipo' => 'Esta obra possui obras filhas. Remova ou edite os vinculos antes de transforma-la em filha.',
            ]);
        }

        $obra->update($data);

        return back()->with('success', 'Obra atualizada com sucesso.');
    }

    public function destroy(Tenant $tenant, Obra $obra): RedirectResponse
    {
        abort_unless((int) $obra->tenant_id === (int) $tenant->id, 404);

        if ($obra->obrasFilhas()->exists()) {
            return back()->with('error', 'Esta obra possui obras filhas e nao pode ser deletada.');
        }

        $hasRncHistory = RelatorioNaoConformidade::query()
            ->where('tenant_id', $tenant->id)
            ->where('obra_id', $obra->id)
            ->exists();

        if ($hasRncHistory) {
            return back()->with('error', 'Esta obra possui historico de RNC e nao pode ser deletada.');
        }

        $isLinkedToContract = Contract::query()
            ->where('tenant_id', $tenant->id)
            ->where('obra_id', $obra->id)
            ->exists();

        if ($isLinkedToContract) {
            return back()->with('error', 'Esta obra esta vinculada a um contrato e nao pode ser deletada.');
        }

        $obra->delete();

        return back()->with('success', 'Obra deletada com sucesso. O registro foi mantido no historico.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedObraData(Request $request, Tenant $tenant, ?Obra $obra = null): array
    {
        $request->merge([
            'codigo' => trim((string) $request->input('codigo', '')),
        ]);

        $uniqueCodigo = Rule::unique('obras', 'codigo')->where(fn ($query) => $query
            ->where('tenant_id', $tenant->id)
            ->where('contract_id', $request->input('contract_id')));

        if ($obra) {
            $uniqueCodigo->ignore($obra->id);
        }

        $data = $request->validate([
            'nome' => ['required', 'string', 'max:255'],
            'contract_id' => [
                'required',
                Rule::exists('contracts', 'id')->where(fn ($query) => $query->where('tenant_id', $tenant->id)),
            ],
            'codigo' => ['required', 'string', 'size:3', 'regex:/^\d{3}$/', $uniqueCodigo],
            'tipo' => ['required', Rule::in(['pai', 'filha'])],
            'obra_pai_id' => [
                'nullable',
                'required_if:tipo,filha',
                Rule::exists('obras', 'id')->where(fn ($query) => $query
                    ->where('tenant_id', $tenant->id)
                    ->where('contract_id', $request->input('contract_id'))
                    ->where('tipo', 'pai')),
            ],
        ], [
            'obra_pai_id.required_if' => 'Selecione a obra pai para cadastrar uma obra filha.',
            'obra_pai_id.exists' => 'A obra pai selecionada nao esta disponivel para este contrato.',
            'contract_id.required' => 'Selecione o contrato.',
            'contract_id.exists' => 'O contrato selecionado nao esta disponivel para este tenant.',
            'codigo.size' => 'O codigo deve conter exatamente 3 digitos.',
            'codigo.regex' => 'O codigo deve conter apenas numeros.',
        ]);

        if ($data['tipo'] === 'pai') {
            $data['obra_pai_id'] = null;
        }

        return $data;
    }
}
