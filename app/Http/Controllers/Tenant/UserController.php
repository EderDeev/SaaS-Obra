<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Support\UserPermissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    public function index(Tenant $tenant): Response
    {
        $this->authorizeTenantUsers($tenant);

        return Inertia::render('Tenant/Users/Index', [
            'tenant' => $tenant,
            'memberships' => $tenant->memberships()
                ->with(['user', 'empresa.tipoEmpresa', 'empresa.contract'])
                ->latest()
                ->get()
                ->each(function (TenantUser $membership): void {
                    $userPermissions = $membership->role === 'tenant_owner'
                        ? UserPermissions::all()
                        : UserPermissions::normalize(
                            $membership->user_permissions ?? UserPermissions::defaultForRole($membership->role),
                        );

                    $membership->setAttribute('user_permissions', $userPermissions);
                }),
            'empresas' => $tenant->empresas()
                ->with(['tipoEmpresa', 'contract'])
                ->orderBy('nome')
                ->get(),
            'roles' => $this->roles(),
            'userPermissionOptions' => UserPermissions::labels(),
            'userPermissionCan' => [
                'create_user' => UserPermissions::can(request()->user(), $tenant, UserPermissions::CREATE),
                'edit_user' => UserPermissions::can(request()->user(), $tenant, UserPermissions::EDIT),
                'deactivate_user' => UserPermissions::can(request()->user(), $tenant, UserPermissions::DEACTIVATE),
            ],
        ]);
    }

    public function store(Request $request, Tenant $tenant): RedirectResponse
    {
        $this->authorizeTenantUsers($tenant, UserPermissions::CREATE);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'empresa_id' => [
                'required',
                Rule::exists('empresas', 'id')->where(fn ($query) => $query->where('tenant_id', $tenant->id)),
            ],
            'role' => ['required', Rule::in($this->roles())],
            'user_permissions' => ['nullable', 'array'],
            'user_permissions.*' => ['required', 'string', Rule::in(UserPermissions::all())],
        ], [
            'empresa_id.required' => 'Selecione a empresa do usuario.',
            'empresa_id.exists' => 'A empresa selecionada nao pertence a este tenant.',
        ]);

        $user = User::firstOrCreate(
            ['email' => mb_strtolower($data['email'])],
            [
                'name' => $data['name'],
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
            ],
        );

        $tenant->memberships()->updateOrCreate(
            ['user_id' => $user->id],
            [
                'empresa_id' => $data['empresa_id'],
                'role' => $data['role'],
                'status' => 'active',
                'user_permissions' => UserPermissions::normalize(
                    $data['user_permissions'] ?? UserPermissions::defaultForRole($data['role']),
                ),
                'invited_at' => now(),
                'joined_at' => now(),
            ],
        );

        return back()->with('success', 'Usuário vinculado. Senha demo para novas contas: password');
    }

    public function update(Request $request, Tenant $tenant, TenantUser $membership): RedirectResponse
    {
        $this->authorizeTenantUsers($tenant, UserPermissions::EDIT);
        $this->ensureMembershipBelongsToTenant($tenant, $membership);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($membership->user_id)],
            'empresa_id' => [
                'required',
                Rule::exists('empresas', 'id')->where(fn ($query) => $query->where('tenant_id', $tenant->id)),
            ],
            'role' => ['required', Rule::in($this->roles())],
            'user_permissions' => ['nullable', 'array'],
            'user_permissions.*' => ['required', 'string', Rule::in(UserPermissions::all())],
        ], [
            'empresa_id.required' => 'Selecione a empresa do usuario.',
            'empresa_id.exists' => 'A empresa selecionada nao pertence a este tenant.',
        ]);

        $membership->user->update([
            'name' => $data['name'],
            'email' => mb_strtolower($data['email']),
        ]);

        $membershipData = [
            'empresa_id' => $data['empresa_id'],
            'role' => $data['role'],
        ];

        if (array_key_exists('user_permissions', $data)) {
            $membershipData['user_permissions'] = UserPermissions::normalize($data['user_permissions'] ?? []);
        }

        $membership->update($membershipData);

        return back()->with('success', 'Usuario atualizado.');
    }

    public function deactivate(Request $request, Tenant $tenant, TenantUser $membership): RedirectResponse
    {
        $this->authorizeTenantUsers($tenant, UserPermissions::DEACTIVATE);
        $this->ensureMembershipBelongsToTenant($tenant, $membership);

        abort_if($membership->user_id === $request->user()->id, 422, 'Voce nao pode desativar o proprio acesso.');

        $membership->update([
            'status' => 'inactive',
        ]);

        return back()->with('success', 'Usuario desativado.');
    }

    private function authorizeTenantUsers(Tenant $tenant, string $permission = UserPermissions::VIEW): void
    {
        abort_unless(UserPermissions::can(request()->user(), $tenant, $permission), 403);
    }

    private function ensureMembershipBelongsToTenant(Tenant $tenant, TenantUser $membership): void
    {
        abort_unless($membership->tenant_id === $tenant->id, 404);
    }

    /**
     * @return array<int, string>
     */
    private function roles(): array
    {
        return ['tenant_owner', 'tenant_admin', 'obras_manager', 'engineer', 'financial', 'viewer'];
    }
}
