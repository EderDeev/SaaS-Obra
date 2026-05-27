<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\Contract;
use App\Models\Tenant;
use App\Models\User;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(): Response
    {
        return Inertia::render('Platform/Dashboard', [
            'stats' => [
                'tenants' => Tenant::count(),
                'activeTenants' => Tenant::where('status', 'active')->count(),
                'contracts' => Contract::count(),
                'users' => User::count(),
            ],
            'recentTenants' => Tenant::query()
                ->withCount(['users', 'contracts'])
                ->latest()
                ->limit(6)
                ->get(),
        ]);
    }
}
