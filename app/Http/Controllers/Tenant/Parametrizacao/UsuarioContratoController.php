<?php

namespace App\Http\Controllers\Tenant\Parametrizacao;

use App\Http\Controllers\Controller;
use App\Models\ContractParticipant;
use App\Models\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class UsuarioContratoController extends Controller
{
    private const ROLES_BY_SIDE = [
        'manager' => ['manager', 'team_member'],
        'client' => ['client_approver', 'client_viewer'],
        'contractor' => ['contractor_lead', 'contractor_member'],
    ];

    public function index(Tenant $tenant): Response
    {
        return Inertia::render('Tenant/Parametrizacao/UsuariosContratos/Index', [
            'tenant' => $tenant,
            'users' => $tenant->memberships()
                ->with('user')
                ->where('status', 'active')
                ->latest()
                ->get()
                ->map(fn ($membership) => [
                    'id' => $membership->user->id,
                    'name' => $membership->user->name,
                    'email' => $membership->user->email,
                    'tenant_role' => $membership->role,
                ])
                ->values(),
            'contracts' => $tenant->contracts()
                ->with('obra')
                ->orderBy('code')
                ->get(),
            'links' => ContractParticipant::query()
                ->with(['user:id,name,email', 'contract:id,code,name,obra_id'])
                ->where('tenant_id', $tenant->id)
                ->where('status', 'active')
                ->latest()
                ->get(),
            'rolesBySide' => self::ROLES_BY_SIDE,
        ]);
    }

    public function store(Request $request, Tenant $tenant): RedirectResponse
    {
        $data = $request->validate([
            'user_id' => [
                'required',
                Rule::exists('tenant_users', 'user_id')->where(fn ($query) => $query
                    ->where('tenant_id', $tenant->id)
                    ->where('status', 'active')),
            ],
            'contract_id' => [
                'required',
                Rule::exists('contracts', 'id')->where(fn ($query) => $query->where('tenant_id', $tenant->id)),
            ],
            'side' => ['required', Rule::in(array_keys(self::ROLES_BY_SIDE))],
            'role' => ['required', 'string'],
        ], [
            'user_id.required' => 'Selecione o usuario.',
            'user_id.exists' => 'O usuario selecionado nao esta ativo neste tenant.',
            'contract_id.required' => 'Selecione o contrato.',
            'contract_id.exists' => 'O contrato selecionado nao pertence a este tenant.',
        ]);

        abort_unless(in_array($data['role'], self::ROLES_BY_SIDE[$data['side']], true), 422);

        $participant = ContractParticipant::withTrashed()->firstOrNew([
            'contract_id' => $data['contract_id'],
            'user_id' => $data['user_id'],
            'side' => $data['side'],
        ]);

        $participant->fill([
            'tenant_id' => $tenant->id,
            'role' => $data['role'],
            'status' => 'active',
            'invited_at' => now(),
            'joined_at' => now(),
        ]);

        if ($participant->trashed()) {
            $participant->restore();
        } else {
            $participant->save();
        }

        return back()->with('success', 'Usuario vinculado ao contrato.');
    }

    public function destroy(Tenant $tenant, ContractParticipant $participant): RedirectResponse
    {
        abort_unless((int) $participant->tenant_id === (int) $tenant->id, 404);

        $participant->update(['status' => 'inactive']);
        $participant->delete();

        return back()->with('success', 'Usuario removido do contrato. O vinculo foi mantido no historico.');
    }
}
