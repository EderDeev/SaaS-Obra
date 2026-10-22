<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\OsDnitHighway;
use App\Models\Tenant;
use App\Support\OsDnitPermissions as Permissions;
use App\Models\OsDnitOrder;
use Illuminate\Support\Facades\DB;
use App\Support\OsDnitContractAccess;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class OsDnitSettingsController extends Controller
{
    public function index(Request $request, Tenant $tenant): Response
    {
        $contracts = OsDnitContractAccess::contracts($request->user(), $tenant, Permissions::VIEW)->orderBy('code')->get(['id', 'code', 'name']);
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:120'], 'contract_id' => ['nullable', 'integer', Rule::in($contracts->pluck('id')->all())]]);

        return Inertia::render('Tenant/OsDnit/Settings', [
            'highways' => OsDnitHighway::query()
                ->where('tenant_id', $tenant->id)
                ->whereIn('contract_id', $contracts->pluck('id'))
                ->with('contract:id,code,name')
                ->when($filters['contract_id'] ?? null, fn ($query, $id) => $query->where('contract_id', $id))
                ->when(trim($filters['q'] ?? ''), fn ($query, $term) => $query
                    ->whereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower($term).'%']))
                ->orderBy('name')
                ->paginate(15)
                ->withQueryString()->through(fn ($highway) => $highway
                    ->setAttribute('can_edit', Permissions::can($request->user(), $tenant, Permissions::EDIT_SETTINGS, $highway->contract_id))
                    ->setAttribute('can_delete', Permissions::can($request->user(), $tenant, Permissions::DELETE_SETTINGS, $highway->contract_id))),
            'filters' => $filters,
            'contracts' => $contracts->map(fn ($contract) => $contract->setAttribute('can_create', Permissions::can($request->user(), $tenant, Permissions::CREATE_SETTINGS, $contract->id))),
            'canCreate' => Permissions::canAny($request->user(), $tenant, Permissions::CREATE_SETTINGS),
            'canManage' => Permissions::canAny($request->user(), $tenant, Permissions::CREATE_SETTINGS) || Permissions::canAny($request->user(), $tenant, Permissions::EDIT_SETTINGS),
        ]);
    }

    public function store(Request $request, Tenant $tenant): RedirectResponse
    {
        $data = $this->validatedHighway($request, $tenant);

        try {
            OsDnitHighway::create([...$data, 'tenant_id' => $tenant->id, 'active' => true]);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['name' => 'Esta rodovia já está cadastrada neste contrato.']);
        }

        return back()->with('success', 'Rodovia cadastrada com sucesso.');
    }

    public function update(Request $request, Tenant $tenant, OsDnitHighway $highway): RedirectResponse
    {
        abort_unless((int) $highway->tenant_id === (int) $tenant->id && OsDnitContractAccess::contracts($request->user(), $tenant)->whereKey($highway->contract_id)->exists(), 404);
        $data = $this->validatedHighway($request, $tenant, $highway);

        try {
            $highway->update($data);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['name' => 'Esta rodovia já está cadastrada neste contrato.']);
        }

        return back()->with('success', 'Rodovia atualizada com sucesso.');
    }

    public function destroy(Request $request, Tenant $tenant, OsDnitHighway $highway): RedirectResponse
    {
        try {
            DB::transaction(function () use ($tenant, $highway) {
                $locked = OsDnitHighway::where('tenant_id', $tenant->id)->whereKey($highway->id)->lockForUpdate()->firstOrFail();
                if (OsDnitOrder::withTrashed()->where('highway_id', $locked->id)->exists()) {
                    throw ValidationException::withMessages(['resource' => 'Esta rodovia está vinculada a uma OS e não pode ser excluída.']);
                }
                $locked->delete();
            });
        } catch (QueryException $exception) {
            if (in_array((string) $exception->getCode(), ['23503', '23000'], true)) {
                throw ValidationException::withMessages(['resource' => 'Esta rodovia está vinculada a uma OS e não pode ser excluída.']);
            }
            throw $exception;
        }
        return back()->with('success', 'Rodovia excluída.');
    }

    private function validatedHighway(Request $request, Tenant $tenant, ?OsDnitHighway $highway = null): array
    {
        if (is_string($request->input('name'))) {
            $request->merge(['name' => trim($request->input('name'))]);
        }

        $contractIds = OsDnitContractAccess::contracts($request->user(), $tenant, $highway ? Permissions::EDIT_SETTINGS : Permissions::CREATE_SETTINGS)->pluck('id')->all();
        $request->validate(['contract_id' => ['required', 'integer', Rule::in($highway ? [$highway->contract_id] : $contractIds)]]);
        $rules = [
            'contract_id' => ['required', 'integer', Rule::in($highway ? [$highway->contract_id] : $contractIds)],
            'name' => ['required', 'string', 'max:255',
                Rule::unique('os_dnit_highways', 'name')->where('tenant_id', $tenant->id)->where('contract_id', $request->input('contract_id'))->ignore($highway?->id)],
        ];
        if ($highway) {
            $rules['active'] = ['required', 'boolean'];
        }

        return $request->validate($rules, [
            'name.required' => 'Informe a rodovia.',
            'name.unique' => 'Esta rodovia já está cadastrada neste contrato.',
        ], ['name' => 'rodovia', 'active' => 'situação']);
    }
}
