<?php

namespace App\Http\Controllers\Tenant\Parametrizacao;

use App\Http\Controllers\Controller;
use App\Models\Obra;
use App\Models\Tenant;
use App\Models\Trecho;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class TrechoController extends Controller
{
    public function index(Tenant $tenant): Response
    {
        return Inertia::render('Tenant/Parametrizacao/Trechos/Index', [
            'tenant' => $tenant,
            'trechos' => $tenant->trechos()
                ->with(['obra:id,tenant_id,contract_id,codigo,nome', 'obra.contract:id,code,name'])
                ->orderBy('obra_id')
                ->orderByDesc('is_default')
                ->orderBy('codigo')
                ->get(),
            'contracts' => $tenant->contracts()
                ->orderBy('code')
                ->get(['id', 'code', 'name']),
            'obras' => $tenant->obras()
                ->with('contract:id,code,name')
                ->orderBy('contract_id')
                ->orderBy('codigo')
                ->get(['id', 'contract_id', 'codigo', 'nome']),
        ]);
    }

    public function store(Request $request, Tenant $tenant): RedirectResponse
    {
        $data = $this->validatedData($request, $tenant);
        $trashed = Trecho::onlyTrashed()
            ->where('tenant_id', $tenant->id)
            ->where('obra_id', $data['obra_id'])
            ->where('codigo', $data['codigo'])
            ->first();

        if ($trashed) {
            $trashed->restore();
            $trashed->update([
                'nome' => $data['nome'],
                'is_default' => false,
            ]);
        } else {
            $tenant->trechos()->create([
                ...$data,
                'is_default' => false,
            ]);
        }

        return back()->with('success', 'Trecho cadastrado com sucesso.');
    }

    public function update(Request $request, Tenant $tenant, Trecho $trecho): RedirectResponse
    {
        $this->ensureTenant($tenant, $trecho);

        if ($trecho->is_default) {
            throw ValidationException::withMessages([
                'codigo' => 'O trecho GER e reservado como localizador geral da obra.',
            ]);
        }

        $trecho->update($this->validatedData($request, $tenant, $trecho));

        return back()->with('success', 'Trecho atualizado com sucesso.');
    }

    public function destroy(Tenant $tenant, Trecho $trecho): RedirectResponse
    {
        $this->ensureTenant($tenant, $trecho);

        if ($trecho->is_default) {
            return back()->with('error', 'O trecho GER e obrigatorio e nao pode ser excluido.');
        }

        if ($trecho->projectDocuments()->withTrashed()->exists()) {
            return back()->with('error', 'Este trecho esta vinculado a projetos e nao pode ser excluido.');
        }

        $trecho->delete();

        return back()->with('success', 'Trecho excluido com sucesso. O registro foi mantido no historico.');
    }

    private function validatedData(Request $request, Tenant $tenant, ?Trecho $trecho = null): array
    {
        $request->merge([
            'codigo' => mb_strtoupper(trim((string) $request->input('codigo', ''))),
            'nome' => trim((string) $request->input('nome', '')),
        ]);

        $uniqueCode = Rule::unique('trechos', 'codigo')->where(fn ($query) => $query
            ->where('tenant_id', $tenant->id)
            ->where('obra_id', $request->input('obra_id')));

        if ($trecho) {
            $uniqueCode->ignore($trecho->id);
        }

        return $request->validate([
            'obra_id' => [
                'required',
                Rule::exists('obras', 'id')->where(fn ($query) => $query->where('tenant_id', $tenant->id)),
            ],
            'codigo' => ['required', 'string', 'size:3', 'regex:/^[A-Z0-9]{3}$/', $uniqueCode],
            'nome' => ['required', 'string', 'max:255'],
        ], [
            'obra_id.required' => 'Selecione a obra do trecho.',
            'obra_id.exists' => 'A obra selecionada nao pertence a este tenant.',
            'codigo.size' => 'O codigo deve conter exatamente 3 caracteres.',
            'codigo.regex' => 'Use somente letras e numeros no codigo do trecho.',
            'codigo.unique' => 'Esta obra ja possui um trecho com esse codigo.',
        ]);
    }

    private function ensureTenant(Tenant $tenant, Trecho $trecho): void
    {
        abort_unless((int) $trecho->tenant_id === (int) $tenant->id, 404);
    }
}
