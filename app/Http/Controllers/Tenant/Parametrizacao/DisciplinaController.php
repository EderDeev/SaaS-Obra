<?php

namespace App\Http\Controllers\Tenant\Parametrizacao;

use App\Http\Controllers\Controller;
use App\Models\Disciplina;
use App\Models\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class DisciplinaController extends Controller
{
    public function index(Tenant $tenant): Response
    {
        return Inertia::render('Tenant/Parametrizacao/Disciplinas/Index', [
            'tenant' => $tenant,
            'disciplinas' => $tenant->disciplinas()
                ->with('contract:id,code,name')
                ->latest()
                ->get(),
            'contracts' => $tenant->contracts()
                ->orderBy('code')
                ->get(['id', 'code', 'name']),
        ]);
    }

    public function store(Request $request, Tenant $tenant): RedirectResponse
    {
        $tenant->disciplinas()->create($this->validatedDisciplinaData($request, $tenant));

        return back()->with('success', 'Disciplina cadastrada com sucesso.');
    }

    public function update(Request $request, Tenant $tenant, Disciplina $disciplina): RedirectResponse
    {
        abort_unless((int) $disciplina->tenant_id === (int) $tenant->id, 404);

        $disciplina->update($this->validatedDisciplinaData($request, $tenant, $disciplina));

        return back()->with('success', 'Disciplina atualizada com sucesso.');
    }

    public function destroy(Tenant $tenant, Disciplina $disciplina): RedirectResponse
    {
        abort_unless((int) $disciplina->tenant_id === (int) $tenant->id, 404);

        $disciplina->delete();

        return back()->with('success', 'Disciplina deletada com sucesso. O registro foi mantido no historico.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedDisciplinaData(Request $request, Tenant $tenant, ?Disciplina $disciplina = null): array
    {
        $request->merge([
            'sigla' => mb_strtoupper((string) $request->input('sigla', '')),
            'cor' => $request->input('cor') ?: '#2563eb',
        ]);

        $uniqueSigla = Rule::unique('disciplinas', 'sigla')
            ->where(fn ($query) => $query
                ->where('tenant_id', $tenant->id)
                ->where('contract_id', $request->input('contract_id')))
            ->withoutTrashed();

        if ($disciplina) {
            $uniqueSigla->ignore($disciplina->id);
        }

        return $request->validate([
            'contract_id' => [
                'required',
                Rule::exists('contracts', 'id')->where(fn ($query) => $query->where('tenant_id', $tenant->id)),
            ],
            'nome' => ['required', 'string', 'max:255'],
            'sigla' => ['required', 'string', 'max:20', $uniqueSigla],
            'descricao' => ['nullable', 'string', 'max:2000'],
            'cor' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        ], [
            'contract_id.required' => 'Selecione o contrato.',
            'contract_id.exists' => 'O contrato selecionado nao pertence a este tenant.',
            'sigla.unique' => 'Esta sigla ja esta cadastrada neste contrato.',
            'cor.regex' => 'Informe uma cor valida no formato hexadecimal.',
        ]);
    }
}
