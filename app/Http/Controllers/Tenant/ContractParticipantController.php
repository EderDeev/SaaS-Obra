<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Contract;
use App\Models\ContractParticipant;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class ContractParticipantController extends Controller
{
    public function store(Request $request, Tenant $tenant, Contract $contract): RedirectResponse
    {
        abort_unless((int) $contract->tenant_id === (int) $tenant->id, 404);
        abort_unless($this->canManageParticipants($request, $tenant, $contract), 403);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'side' => ['required', Rule::in(['client', 'contractor', 'manager'])],
            'role' => ['required', Rule::in([
                'manager',
                'team_member',
                'client_approver',
                'client_viewer',
                'contractor_lead',
                'contractor_member',
            ])],
        ]);

        $validRoleBySide = [
            'client' => ['client_approver', 'client_viewer'],
            'contractor' => ['contractor_lead', 'contractor_member'],
            'manager' => ['manager', 'team_member'],
        ];

        abort_unless(in_array($data['role'], $validRoleBySide[$data['side']], true), 422);

        $user = User::firstOrCreate(
            ['email' => mb_strtolower($data['email'])],
            [
                'name' => $data['name'],
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
            ],
        );

        $participant = ContractParticipant::withTrashed()->firstOrNew([
            'contract_id' => $contract->id,
            'user_id' => $user->id,
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

        return back()->with('success', 'Participante vinculado ao contrato. Senha demo para novas contas: password');
    }

    private function canManageParticipants(Request $request, Tenant $tenant, Contract $contract): bool
    {
        $tenantRole = $request->user()->tenantRole($tenant);

        if (in_array($tenantRole, ['tenant_owner', 'tenant_admin'], true)) {
            return true;
        }

        return $contract->participants()
            ->where('user_id', $request->user()->id)
            ->where('side', 'manager')
            ->where('role', 'manager')
            ->where('status', 'active')
            ->exists();
    }
}
