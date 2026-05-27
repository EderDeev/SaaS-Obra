<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request, Tenant $tenant): Response
    {
        $user = $request->user();
        $tenantRole = $user->tenantRole($tenant);
        $canSeeAllContracts = in_array($tenantRole, ['tenant_owner', 'tenant_admin'], true);

        $contracts = $tenant->contracts();

        if (! $canSeeAllContracts) {
            $contracts->whereHas('participants', function ($query) use ($user): void {
                $query->where('user_id', $user->id)->where('status', 'active');
            });
        }

        return Inertia::render('Tenant/Dashboard', [
            'tenant' => $tenant,
            'stats' => [
                'contracts' => (clone $contracts)->count(),
                'activeContracts' => (clone $contracts)->where('status', 'active')->count(),
                'users' => $canSeeAllContracts ? $tenant->memberships()->count() : 0,
                'externalParticipants' => $canSeeAllContracts ? $tenant->contracts()
                    ->join('contract_participants', 'contracts.id', '=', 'contract_participants.contract_id')
                    ->whereIn('contract_participants.side', ['client', 'contractor'])
                    ->count() : 0,
            ],
            'recentContracts' => $contracts
                ->withCount('participants')
                ->latest()
                ->limit(6)
                ->get(),
            'role' => $tenantRole,
        ]);
    }
}
