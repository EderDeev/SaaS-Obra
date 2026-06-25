<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\RdoEquipamentoCadastro;
use App\Models\RdoMaoObraCadastro;
use App\Models\RdoSubcontratadaCadastro;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class RdoCadastroController extends Controller
{
    public function index(Tenant $tenant): Response
    {
        return Inertia::render('Tenant/Rdo/Cadastros', [
            'maoObra' => RdoMaoObraCadastro::query()
                ->where('tenant_id', $tenant->id)
                ->orderBy('tipo')
                ->orderBy('descricao')
                ->get(),
            'equipamentos' => RdoEquipamentoCadastro::query()
                ->where('tenant_id', $tenant->id)
                ->orderBy('descricao')
                ->get(),
            'subcontratadas' => RdoSubcontratadaCadastro::query()
                ->where('tenant_id', $tenant->id)
                ->orderBy('razao_social')
                ->get(),
        ]);
    }

    public function storeMaoObra(Request $request, Tenant $tenant): RedirectResponse
    {
        $data = $request->validate([
            'descricao' => [
                'required', 'string', 'max:255',
                Rule::unique('rdo_mao_obra_cadastros')->where(fn ($query) => $query
                    ->where('tenant_id', $tenant->id)
                    ->where('tipo', $request->input('tipo'))
                    ->whereNull('deleted_at')),
            ],
            'tipo' => ['required', Rule::in(['direta', 'indireta'])],
            'unidade' => ['required', 'string', 'max:30'],
            'active' => ['required', 'boolean'],
        ]);

        RdoMaoObraCadastro::create(['tenant_id' => $tenant->id, ...$data]);

        return back()->with('success', 'Mão de obra cadastrada com sucesso.');
    }

    public function updateMaoObra(Request $request, Tenant $tenant, RdoMaoObraCadastro $maoObra): RedirectResponse
    {
        $this->ensureTenant($tenant, $maoObra);
        $data = $request->validate([
            'descricao' => [
                'required', 'string', 'max:255',
                Rule::unique('rdo_mao_obra_cadastros')->ignore($maoObra->id)->where(fn ($query) => $query
                    ->where('tenant_id', $tenant->id)
                    ->where('tipo', $request->input('tipo'))
                    ->whereNull('deleted_at')),
            ],
            'tipo' => ['required', Rule::in(['direta', 'indireta'])],
            'unidade' => ['required', 'string', 'max:30'],
            'active' => ['required', 'boolean'],
        ]);
        $maoObra->update($data);

        return back()->with('success', 'Mão de obra atualizada com sucesso.');
    }

    public function destroyMaoObra(Tenant $tenant, RdoMaoObraCadastro $maoObra): RedirectResponse
    {
        $this->ensureTenant($tenant, $maoObra);
        $maoObra->delete();

        return back()->with('success', 'Mão de obra removida.');
    }

    public function storeEquipamento(Request $request, Tenant $tenant): RedirectResponse
    {
        $data = $this->equipmentData($request, $tenant);
        RdoEquipamentoCadastro::create(['tenant_id' => $tenant->id, ...$data]);

        return back()->with('success', 'Equipamento cadastrado com sucesso.');
    }

    public function updateEquipamento(Request $request, Tenant $tenant, RdoEquipamentoCadastro $equipamento): RedirectResponse
    {
        $this->ensureTenant($tenant, $equipamento);
        $equipamento->update($this->equipmentData($request, $tenant, $equipamento));

        return back()->with('success', 'Equipamento atualizado com sucesso.');
    }

    public function destroyEquipamento(Tenant $tenant, RdoEquipamentoCadastro $equipamento): RedirectResponse
    {
        $this->ensureTenant($tenant, $equipamento);
        $equipamento->delete();

        return back()->with('success', 'Equipamento removido.');
    }

    public function storeSubcontratada(Request $request, Tenant $tenant): RedirectResponse
    {
        $data = $this->subcontractorData($request, $tenant);
        RdoSubcontratadaCadastro::create(['tenant_id' => $tenant->id, ...$data]);

        return back()->with('success', 'Subcontratada cadastrada com sucesso.');
    }

    public function updateSubcontratada(Request $request, Tenant $tenant, RdoSubcontratadaCadastro $subcontratada): RedirectResponse
    {
        $this->ensureTenant($tenant, $subcontratada);
        $subcontratada->update($this->subcontractorData($request, $tenant, $subcontratada));

        return back()->with('success', 'Subcontratada atualizada com sucesso.');
    }

    public function destroySubcontratada(Tenant $tenant, RdoSubcontratadaCadastro $subcontratada): RedirectResponse
    {
        $this->ensureTenant($tenant, $subcontratada);
        $subcontratada->delete();

        return back()->with('success', 'Subcontratada removida.');
    }

    private function equipmentData(Request $request, Tenant $tenant, ?RdoEquipamentoCadastro $equipment = null): array
    {
        return $request->validate([
            'codigo' => [
                'nullable', 'string', 'max:50',
                Rule::unique('rdo_equipamento_cadastros')->ignore($equipment?->id)->where(fn ($query) => $query
                    ->where('tenant_id', $tenant->id)
                    ->whereNull('deleted_at')),
            ],
            'descricao' => ['required', 'string', 'max:255'],
            'unidade' => ['required', 'string', 'max:30'],
            'propriedade' => ['required', Rule::in(['proprio', 'locado', 'subcontratada'])],
            'active' => ['required', 'boolean'],
        ]);
    }

    private function subcontractorData(Request $request, Tenant $tenant, ?RdoSubcontratadaCadastro $subcontractor = null): array
    {
        $request->merge(['cnpj' => $this->formatCnpj((string) $request->input('cnpj', ''))]);

        return $request->validate([
            'razao_social' => ['required', 'string', 'max:255'],
            'nome_fantasia' => ['nullable', 'string', 'max:255'],
            'cnpj' => [
                'nullable', 'string', 'size:18', 'regex:/^\d{2}\.\d{3}\.\d{3}\/\d{4}-\d{2}$/',
                Rule::unique('rdo_subcontratada_cadastros')->ignore($subcontractor?->id)->where(fn ($query) => $query
                    ->where('tenant_id', $tenant->id)
                    ->whereNull('deleted_at')),
            ],
            'responsavel' => ['nullable', 'string', 'max:255'],
            'telefone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'active' => ['required', 'boolean'],
        ]);
    }

    private function ensureTenant(Tenant $tenant, Model $model): void
    {
        abort_unless((int) $model->tenant_id === (int) $tenant->id, 404);
    }

    private function formatCnpj(string $value): ?string
    {
        $digits = preg_replace('/\D/', '', $value) ?? '';
        if ($digits === '') {
            return null;
        }
        if (strlen($digits) !== 14) {
            return $value;
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
