<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Support\ActivityPermissions;
use App\Support\ParametrizacaoPermissions;
use App\Support\ProjectPermissions;
use App\Support\RncPermissions;
use App\Support\TenantRoles;
use App\Support\UserPermissions;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $tenantForNavigation = function () use ($request): ?Tenant {
            static $resolved = false;
            static $tenant = null;

            if (! $resolved) {
                $tenant = $this->tenantForNavigation($request);
                $resolved = true;
            }

            return $tenant;
        };

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $request->user(),
            ],
            'notifications' => fn () => $request->user()
                ? [
                    'unread_count' => $request->user()->unreadNotifications()->count(),
                    'items' => $request->user()->notifications()
                        ->latest()
                        ->limit(8)
                        ->get()
                        ->map(fn ($notification): array => [
                            'id' => $notification->id,
                            'title' => $notification->data['title'] ?? 'Notificação',
                            'body' => $notification->data['body'] ?? '',
                            'url' => $notification->data['url'] ?? null,
                            'contract' => $notification->data['contract'] ?? null,
                            'time' => $notification->created_at?->format('d/m H:i'),
                            'unread' => $notification->read_at === null,
                        ])
                        ->values(),
                ]
                : ['unread_count' => 0, 'items' => []],
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
                'import_result' => fn () => $request->session()->get('import_result'),
                'reset_password' => fn () => $request->session()->get('reset_password'),
            ],
            'currentTenant' => fn () => $tenantForNavigation(),
            'currentTenantRole' => function () use ($request, $tenantForNavigation): ?string {
                $tenant = $tenantForNavigation();

                return $request->user() && $tenant
                    ? $request->user()->tenantRole($tenant)
                    : null;
            },
            'currentTenantRoleLabel' => function () use ($request, $tenantForNavigation): ?string {
                $tenant = $tenantForNavigation();

                return $request->user() && $tenant
                    ? TenantRoles::label($request->user()->tenantRole($tenant))
                    : null;
            },
            'navigationContracts' => function () use ($request, $tenantForNavigation): array {
                $tenant = $tenantForNavigation();
                $user = $request->user();

                if (! $user || ! $tenant) {
                    return [];
                }

                $query = $tenant->contracts()
                    ->select(['id', 'tenant_id', 'code', 'name', 'status'])
                    ->orderBy('code');

                $tenantRole = $user->tenantRole($tenant);

                if (! $user->is_platform_admin && ! in_array($tenantRole, ['tenant_owner', 'tenant_admin'], true)) {
                    $query->whereHas('participants', function ($query) use ($user): void {
                        $query->where('user_id', $user->id)->where('status', 'active');
                    });
                }

                return $query->get()
                    ->map(fn ($contract): array => [
                        'id' => $contract->id,
                        'code' => $contract->code,
                        'name' => $contract->name,
                        'status' => $contract->status,
                    ])
                    ->all();
            },
            'rncPermissions' => function () use ($request, $tenantForNavigation): array {
                $tenant = $tenantForNavigation();

                return $request->user() && $tenant
                    ? [
                    'all' => RncPermissions::all(),
                    'labels' => RncPermissions::labels(),
                    'can' => collect(RncPermissions::all())
                        ->mapWithKeys(fn (string $permission): array => [
                            $permission => RncPermissions::canAny($request->user(), $tenant, $permission),
                        ])
                        ->all(),
                    ]
                    : ['all' => [], 'labels' => [], 'can' => []];
            },
            'activityPermissions' => function () use ($request, $tenantForNavigation): array {
                $tenant = $tenantForNavigation();

                return $request->user() && $tenant
                    ? [
                    'all' => ActivityPermissions::all(),
                    'labels' => ActivityPermissions::labels(),
                    'can' => collect(ActivityPermissions::all())
                        ->mapWithKeys(fn (string $permission): array => [
                            $permission => ActivityPermissions::canAny($request->user(), $tenant, $permission),
                        ])
                        ->all(),
                    ]
                    : ['all' => [], 'labels' => [], 'can' => []];
            },
            'projectPermissions' => function () use ($request, $tenantForNavigation): array {
                $tenant = $tenantForNavigation();

                return $request->user() && $tenant
                    ? [
                    'all' => ProjectPermissions::all(),
                    'labels' => ProjectPermissions::labels(),
                    'can' => collect(ProjectPermissions::all())
                        ->mapWithKeys(fn (string $permission): array => [
                            $permission => ProjectPermissions::canAny($request->user(), $tenant, $permission),
                        ])
                        ->all(),
                    ]
                    : ['all' => [], 'labels' => [], 'can' => []];
            },
            'userPermissions' => function () use ($request, $tenantForNavigation): array {
                $tenant = $tenantForNavigation();

                return $request->user() && $tenant
                    ? [
                    'all' => UserPermissions::all(),
                    'labels' => UserPermissions::labels(),
                    'can' => collect(UserPermissions::all())
                        ->mapWithKeys(fn (string $permission): array => [
                            $permission => UserPermissions::canAny($request->user(), $tenant, $permission),
                        ])
                        ->all(),
                    ]
                    : ['all' => [], 'labels' => [], 'can' => []];
            },
            'parametrizacaoPermissions' => function () use ($request, $tenantForNavigation): array {
                $tenant = $tenantForNavigation();

                return $request->user() && $tenant
                    ? [
                    'all' => ParametrizacaoPermissions::all(),
                    'labels' => ParametrizacaoPermissions::labels(),
                    'can' => collect(ParametrizacaoPermissions::all())
                        ->mapWithKeys(fn (string $permission): array => [
                            $permission => ParametrizacaoPermissions::canAny($request->user(), $tenant, $permission),
                        ])
                        ->all(),
                    ]
                    : ['all' => [], 'labels' => [], 'can' => []];
            },
        ];
    }

    private function tenantForNavigation(Request $request): ?Tenant
    {
        $tenant = $request->attributes->get('tenant');

        if ($tenant instanceof Tenant) {
            return $tenant;
        }

        $user = $request->user();

        if (! $user || ! $request->routeIs('profile.*')) {
            return null;
        }

        $sessionTenantId = $request->session()->get('current_tenant_id');

        if ($sessionTenantId) {
            $tenant = Tenant::query()
                ->whereKey($sessionTenantId)
                ->where('status', '!=', 'suspended')
                ->first();

            if ($tenant instanceof Tenant && $user->hasTenantAccess($tenant)) {
                return $tenant;
            }
        }

        return $user->tenants()
            ->wherePivot('status', 'active')
            ->where('tenants.status', '!=', 'suspended')
            ->first()
            ?? $user->contractParticipations()
                ->with('tenant')
                ->where('status', 'active')
                ->whereHas('tenant', fn ($query) => $query->where('status', '!=', 'suspended'))
                ->first()
                ?->tenant;
    }
}
