<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\ContractParticipant;
use App\Models\OsDnitResponsible;
use App\Models\Tenant;
use App\Support\OsDnitContractAccess;
use App\Support\OsDnitPermissions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class OsDnitResponsibleController extends Controller
{
    public function index(Request $request, Tenant $tenant)
    {
        $contracts = OsDnitContractAccess::contracts($request->user(), $tenant, OsDnitPermissions::RESPONSIBLES)->orderBy('code')->get(['id', 'code', 'name']);

        return Inertia::render('Tenant/OsDnit/Responsibles', [
            'contracts' => $contracts, 'roles' => OsDnitResponsible::ROLES,
            'participants' => ContractParticipant::where('tenant_id', $tenant->id)->whereIn('contract_id', $contracts->pluck('id'))
                ->where('status', 'active')->with('user:id,name')->get(['contract_id', 'user_id']),
            'responsibles' => OsDnitResponsible::where('tenant_id', $tenant->id)->whereIn('contract_id', $contracts->pluck('id'))
                ->where('active', true)->get(['contract_id', 'user_id', 'role']),
            'canManage' => $contracts->isNotEmpty(),
        ]);
    }

    public function store(Request $request, Tenant $tenant)
    {
        $allowed = OsDnitContractAccess::contracts($request->user(), $tenant, OsDnitPermissions::RESPONSIBLES)->pluck('id')->all();
        $request->validate(['contract_id' => ['required', 'integer', Rule::in($allowed)]]);
        $contractId = (int) $request->input('contract_id');
        $data = $request->validate([
            'assignments' => ['present', 'array', 'max:300'],
            'assignments.*.role' => ['required', Rule::in(array_keys(OsDnitResponsible::ROLES))],
            'assignments.*.user_id' => ['required', 'integer', Rule::exists('contract_participants', 'user_id')
                ->where('tenant_id', $tenant->id)->where('contract_id', $contractId)->where('status', 'active')->whereNull('deleted_at')],
        ], ['assignments.*.user_id.exists' => 'Selecione somente usuários com vínculo ativo no contrato.']);
        DB::transaction(function () use ($request, $tenant, $contractId, $data) {
            OsDnitContractAccess::contracts($request->user(), $tenant, OsDnitPermissions::RESPONSIBLES)->whereKey($contractId)->lockForUpdate()->firstOrFail();
            OsDnitResponsible::where('tenant_id', $tenant->id)->where('contract_id', $contractId)->update(['active' => false]);
            foreach ($data['assignments'] as $assignment) {
                OsDnitResponsible::updateOrCreate(['tenant_id' => $tenant->id, 'contract_id' => $contractId,
                    'user_id' => $assignment['user_id'], 'role' => $assignment['role']], ['active' => true]);
            }
        });

        return back()->with('success', 'Responsáveis do contrato atualizados.');
    }
}
