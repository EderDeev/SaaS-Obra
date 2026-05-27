<?php

namespace App\Http\Middleware;

use App\Support\ActivityPermissions;
use App\Support\ParametrizacaoPermissions;
use App\Support\ProjectPermissions;
use App\Support\RncPermissions;
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
            ],
            'currentTenant' => fn () => $request->attributes->get('tenant'),
            'currentTenantRole' => fn () => $request->user() && $request->attributes->get('tenant')
                ? $request->user()->tenantRole($request->attributes->get('tenant'))
                : null,
            'rncPermissions' => fn () => $request->user() && $request->attributes->get('tenant')
                ? [
                    'all' => RncPermissions::all(),
                    'labels' => RncPermissions::labels(),
                    'can' => collect(RncPermissions::all())
                        ->mapWithKeys(fn (string $permission): array => [
                            $permission => RncPermissions::canAny($request->user(), $request->attributes->get('tenant'), $permission),
                        ])
                        ->all(),
                ]
                : ['all' => [], 'labels' => [], 'can' => []],
            'activityPermissions' => fn () => $request->user() && $request->attributes->get('tenant')
                ? [
                    'all' => ActivityPermissions::all(),
                    'labels' => ActivityPermissions::labels(),
                    'can' => collect(ActivityPermissions::all())
                        ->mapWithKeys(fn (string $permission): array => [
                            $permission => ActivityPermissions::canAny($request->user(), $request->attributes->get('tenant'), $permission),
                        ])
                        ->all(),
                ]
                : ['all' => [], 'labels' => [], 'can' => []],
            'projectPermissions' => fn () => $request->user() && $request->attributes->get('tenant')
                ? [
                    'all' => ProjectPermissions::all(),
                    'labels' => ProjectPermissions::labels(),
                    'can' => collect(ProjectPermissions::all())
                        ->mapWithKeys(fn (string $permission): array => [
                            $permission => ProjectPermissions::canAny($request->user(), $request->attributes->get('tenant'), $permission),
                        ])
                        ->all(),
                ]
                : ['all' => [], 'labels' => [], 'can' => []],
            'userPermissions' => fn () => $request->user() && $request->attributes->get('tenant')
                ? [
                    'all' => UserPermissions::all(),
                    'labels' => UserPermissions::labels(),
                    'can' => collect(UserPermissions::all())
                        ->mapWithKeys(fn (string $permission): array => [
                            $permission => UserPermissions::canAny($request->user(), $request->attributes->get('tenant'), $permission),
                        ])
                        ->all(),
                ]
                : ['all' => [], 'labels' => [], 'can' => []],
            'parametrizacaoPermissions' => fn () => $request->user() && $request->attributes->get('tenant')
                ? [
                    'all' => ParametrizacaoPermissions::all(),
                    'labels' => ParametrizacaoPermissions::labels(),
                    'can' => collect(ParametrizacaoPermissions::all())
                        ->mapWithKeys(fn (string $permission): array => [
                            $permission => ParametrizacaoPermissions::canAny($request->user(), $request->attributes->get('tenant'), $permission),
                        ])
                        ->all(),
                ]
                : ['all' => [], 'labels' => [], 'can' => []],
        ];
    }
}
