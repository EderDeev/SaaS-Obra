<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Contract;
use App\Models\ContractParticipant;
use App\Models\RelatorioNaoConformidadeResponsavel;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Support\ActivityPermissions;
use App\Support\ParametrizacaoPermissions;
use App\Support\ProjectPermissions;
use App\Support\RncPermissions;
use App\Support\TenantRoles;
use App\Support\UserPermissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class PermissionController extends Controller
{
    public function index(Request $request, Tenant $tenant): Response
    {
        $this->authorizePermissions($request, $tenant);

        $memberships = $tenant->memberships()
            ->where('status', 'active')
            ->with(['user:id,name,email,avatar_url', 'empresa:id,nome'])
            ->orderBy('role')
            ->latest()
            ->get();

        $contracts = $tenant->contracts()
            ->with('obra:id,nome')
            ->orderBy('code')
            ->get();

        $contractParticipants = ContractParticipant::query()
            ->where('tenant_id', $tenant->id)
            ->where('status', 'active')
            ->get(['id', 'contract_id', 'user_id', 'activity_permissions', 'project_permissions']);

        $rncResponsaveis = RelatorioNaoConformidadeResponsavel::query()
            ->where('tenant_id', $tenant->id)
            ->where('status', 'active')
            ->get(['contract_id', 'user_id', 'permissions']);

        return Inertia::render('Tenant/Permissions/Index', [
            'tenant' => $tenant,
            'users' => $memberships->map(fn (TenantUser $membership): array => [
                'id' => $membership->user_id,
                'membership_id' => $membership->id,
                'name' => $membership->user?->name,
                'email' => $membership->user?->email,
                'avatar_url' => $membership->user?->avatar_url,
                'role' => $membership->role,
                'role_label' => TenantRoles::label($membership->role),
                'empresa' => $membership->empresa?->nome,
            ])->values(),
            'contracts' => $contracts->map(fn (Contract $contract): array => [
                'id' => $contract->id,
                'code' => $contract->code,
                'name' => $contract->obra?->nome ?? $contract->name,
                'status' => $contract->status,
            ])->values(),
            'contractIdsByUser' => $this->contractIdsByUser($memberships, $contracts, $contractParticipants),
            'activityPermissionsByUserContract' => $this->activityPermissionsByUserContract($memberships, $contracts, $contractParticipants),
            'projectPermissionsByUserContract' => $this->projectPermissionsByUserContract($memberships, $contracts, $contractParticipants),
            'rncPermissionsByUserContract' => $this->rncPermissionsByUserContract($memberships, $contracts, $rncResponsaveis),
            'userPermissionsByUser' => $memberships
                ->mapWithKeys(fn (TenantUser $membership): array => [
                    $membership->user_id => $membership->role === 'tenant_owner'
                        ? UserPermissions::all()
                        : UserPermissions::normalize($membership->user_permissions ?? UserPermissions::defaultForRole($membership->role)),
                ])
                ->all(),
            'parametrizacaoPermissionsByUser' => $memberships
                ->mapWithKeys(fn (TenantUser $membership): array => [
                    $membership->user_id => $membership->role === 'tenant_owner'
                        ? ParametrizacaoPermissions::all()
                        : ParametrizacaoPermissions::normalize($membership->parametrizacao_permissions ?? ParametrizacaoPermissions::defaultForRole($membership->role)),
                ])
                ->all(),
            'permissionGroups' => [
                'activities' => [
                    'label' => 'Atividades',
                    'permissions' => ActivityPermissions::labels(),
                ],
                'rnc' => [
                    'label' => 'RNC',
                    'permissions' => RncPermissions::labels(),
                ],
                'projects' => [
                    'label' => 'Projetos',
                    'permissions' => ProjectPermissions::labels(),
                ],
                'users' => [
                    'label' => 'Usuarios',
                    'permissions' => UserPermissions::labels(),
                ],
                'parametrizacao' => [
                    'label' => 'Parametrizacao',
                    'permissions' => ParametrizacaoPermissions::labels(),
                ],
            ],
        ]);
    }

    public function update(Request $request, Tenant $tenant): RedirectResponse
    {
        $this->authorizePermissions($request, $tenant);

        $data = $request->validate([
            'user_id' => ['required', 'integer', Rule::exists('tenant_users', 'user_id')->where(fn ($query) => $query->where('tenant_id', $tenant->id)->where('status', 'active'))],
            'contract_id' => ['required', 'integer', Rule::exists('contracts', 'id')->where(fn ($query) => $query->where('tenant_id', $tenant->id))],
            'activity_permissions' => ['nullable', 'array'],
            'activity_permissions.*' => ['required', 'string', Rule::in(ActivityPermissions::all())],
            'project_permissions' => ['nullable', 'array'],
            'project_permissions.*' => ['required', 'string', Rule::in(ProjectPermissions::all())],
            'rnc_permissions' => ['nullable', 'array'],
            'rnc_permissions.*' => ['required', 'string', Rule::in(RncPermissions::all())],
            'user_permissions' => ['nullable', 'array'],
            'user_permissions.*' => ['required', 'string', Rule::in(UserPermissions::all())],
            'parametrizacao_permissions' => ['nullable', 'array'],
            'parametrizacao_permissions.*' => ['required', 'string', Rule::in(ParametrizacaoPermissions::all())],
        ]);

        $membership = $tenant->memberships()
            ->where('user_id', $data['user_id'])
            ->where('status', 'active')
            ->firstOrFail();
        $contract = $tenant->contracts()->findOrFail($data['contract_id']);

        if ($membership->role === 'tenant_owner') {
            return back()->with('success', 'Owner mantem acesso total automaticamente.');
        }

        $activityPermissions = ActivityPermissions::normalize($data['activity_permissions'] ?? []);
        $projectPermissions = ProjectPermissions::normalize($data['project_permissions'] ?? []);
        $rncPermissions = RncPermissions::normalize($data['rnc_permissions'] ?? []);
        $userPermissions = UserPermissions::normalize($data['user_permissions'] ?? []);
        $parametrizacaoPermissions = ParametrizacaoPermissions::normalize($data['parametrizacao_permissions'] ?? []);

        if ($membership->role === 'tenant_admin') {
            $membership->update([
                'activity_permissions' => $activityPermissions,
                'project_permissions' => $projectPermissions,
                'user_permissions' => $userPermissions,
                'parametrizacao_permissions' => $parametrizacaoPermissions,
            ]);
        } else {
            $participant = ContractParticipant::query()
                ->where('tenant_id', $tenant->id)
                ->where('contract_id', $contract->id)
                ->where('user_id', $membership->user_id)
                ->where('status', 'active')
                ->first();

            if (! $participant) {
                throw ValidationException::withMessages([
                    'contract_id' => 'Este usuario nao esta vinculado ao contrato selecionado.',
                ]);
            }

            $participant->update([
                'activity_permissions' => $activityPermissions,
                'project_permissions' => $projectPermissions,
            ]);

            $membership->update([
                'user_permissions' => $userPermissions,
                'parametrizacao_permissions' => $parametrizacaoPermissions,
            ]);
        }

        $this->syncRncPermissions($request, $tenant, $contract, $membership, $rncPermissions);

        return back()->with('success', 'Permissoes atualizadas.');
    }

    private function authorizePermissions(Request $request, Tenant $tenant): void
    {
        abort_unless(in_array($request->user()?->tenantRole($tenant), ['tenant_owner', 'tenant_admin'], true), 403);
    }

    private function syncRncPermissions(Request $request, Tenant $tenant, Contract $contract, TenantUser $membership, array $permissions): void
    {
        if ($membership->role === 'tenant_owner') {
            return;
        }

        $link = RelatorioNaoConformidadeResponsavel::withTrashed()
            ->where('tenant_id', $tenant->id)
            ->where('contract_id', $contract->id)
            ->where('user_id', $membership->user_id)
            ->first();

        if ($permissions === []) {
            if ($link) {
                $link->update([
                    'status' => 'inactive',
                    'permissions' => [],
                ]);

                if (! $link->trashed()) {
                    $link->delete();
                }
            }

            return;
        }

        $link ??= new RelatorioNaoConformidadeResponsavel([
            'contract_id' => $contract->id,
            'user_id' => $membership->user_id,
        ]);

        $link->fill([
            'tenant_id' => $tenant->id,
            'created_by_id' => $link->created_by_id ?? $request->user()->id,
            'status' => 'active',
            'permissions' => $permissions,
        ]);
        $link->save();

        if ($link->trashed()) {
            $link->restore();
        }
    }

    private function contractIdsByUser($memberships, $contracts, $participants): array
    {
        return $memberships
            ->mapWithKeys(function (TenantUser $membership) use ($contracts, $participants): array {
                $contractIds = in_array($membership->role, ['tenant_owner', 'tenant_admin'], true)
                    ? $contracts->pluck('id')->map(fn ($id): int => (int) $id)->values()
                    : $participants
                        ->where('user_id', $membership->user_id)
                        ->pluck('contract_id')
                        ->map(fn ($id): int => (int) $id)
                        ->unique()
                        ->values();

                return [$membership->user_id => $contractIds->all()];
            })
            ->all();
    }

    private function activityPermissionsByUserContract($memberships, $contracts, $participants): array
    {
        $matrix = [];

        foreach ($memberships as $membership) {
            foreach ($contracts as $contract) {
                $key = "{$membership->user_id}:{$contract->id}";

                if ($membership->role === 'tenant_owner') {
                    $matrix[$key] = ActivityPermissions::all();
                    continue;
                }

                if ($membership->role === 'tenant_admin') {
                    $matrix[$key] = ActivityPermissions::normalize($membership->activity_permissions ?? ActivityPermissions::defaultForRole($membership->role));
                    continue;
                }

                $participant = $participants
                    ->where('user_id', $membership->user_id)
                    ->where('contract_id', $contract->id)
                    ->first();

                $matrix[$key] = $participant
                    ? ActivityPermissions::normalize($participant->activity_permissions ?? $membership->activity_permissions ?? ActivityPermissions::defaultForRole($membership->role))
                    : [];
            }
        }

        return $matrix;
    }

    private function projectPermissionsByUserContract($memberships, $contracts, $participants): array
    {
        $matrix = [];

        foreach ($memberships as $membership) {
            foreach ($contracts as $contract) {
                $key = "{$membership->user_id}:{$contract->id}";

                if ($membership->role === 'tenant_owner') {
                    $matrix[$key] = ProjectPermissions::all();
                    continue;
                }

                if ($membership->role === 'tenant_admin') {
                    $matrix[$key] = ProjectPermissions::normalize($membership->project_permissions ?? ProjectPermissions::defaultForRole($membership->role));
                    continue;
                }

                $participant = $participants
                    ->where('user_id', $membership->user_id)
                    ->where('contract_id', $contract->id)
                    ->first();

                $matrix[$key] = $participant
                    ? ProjectPermissions::normalize($participant->project_permissions ?? $membership->project_permissions ?? ProjectPermissions::defaultForRole($membership->role))
                    : [];
            }
        }

        return $matrix;
    }

    private function rncPermissionsByUserContract($memberships, $contracts, $responsaveis): array
    {
        $matrix = [];

        foreach ($memberships as $membership) {
            foreach ($contracts as $contract) {
                $key = "{$membership->user_id}:{$contract->id}";

                if ($membership->role === 'tenant_owner') {
                    $matrix[$key] = RncPermissions::all();
                    continue;
                }

                $responsavel = $responsaveis
                    ->where('user_id', $membership->user_id)
                    ->where('contract_id', $contract->id)
                    ->first();

                $matrix[$key] = $responsavel
                    ? RncPermissions::normalize($responsavel->permissions ?? [])
                    : [];
            }
        }

        return $matrix;
    }
}
