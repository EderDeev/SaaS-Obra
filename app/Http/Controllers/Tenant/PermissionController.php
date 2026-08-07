<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Contract;
use App\Models\ContractParticipant;
use App\Models\RelatorioNaoConformidadeResponsavel;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Support\ActivityPermissions;
use App\Support\BudgetPermissions;
use App\Support\ContractPermissions;
use App\Support\DiarioObraPermissions;
use App\Support\DocumentationPermissions;
use App\Support\MedicaoPermissions;
use App\Support\OrdemServicoPermissions;
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
            ->get(['id', 'contract_id', 'user_id', 'activity_permissions', 'project_permissions', 'documentation_permissions', 'diario_obra_permissions', 'ordem_servico_permissions', 'medicao_permissions', 'contract_permissions']);

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
            'documentationPermissionsByUserContract' => $this->documentationPermissionsByUserContract($memberships, $contracts, $contractParticipants),
            'diarioObraPermissionsByUserContract' => $this->diarioObraPermissionsByUserContract($memberships, $contracts, $contractParticipants),
            'ordemServicoPermissionsByUserContract' => $this->ordemServicoPermissionsByUserContract($memberships, $contracts, $contractParticipants),
            'medicaoPermissionsByUserContract' => $this->medicaoPermissionsByUserContract($memberships, $contracts, $contractParticipants),
            'contractPermissionsByUserContract' => $this->contractPermissionsByUserContract($memberships, $contracts, $contractParticipants),
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
            'budgetPermissionsByUser' => $memberships
                ->mapWithKeys(fn (TenantUser $membership): array => [
                    $membership->user_id => $membership->role === 'tenant_owner'
                        ? BudgetPermissions::all()
                        : BudgetPermissions::normalize($membership->budget_permissions ?? BudgetPermissions::defaultForRole($membership->role)),
                ])
                ->all(),
            'permissionGroups' => [
                'activities' => [
                    'label' => 'Atividades',
                    'permissions' => ActivityPermissions::labels(),
                ],
                'contracts' => [
                    'label' => 'Contratos',
                    'permissions' => ContractPermissions::labels(),
                ],
                'rnc' => [
                    'label' => 'RNC',
                    'permissions' => RncPermissions::labels(),
                ],
                'projects' => [
                    'label' => 'Projetos',
                    'permissions' => ProjectPermissions::labels(),
                ],
                'budgets' => [
                    'label' => 'Orçamentos',
                    'permissions' => BudgetPermissions::labels(),
                ],
                'documentation' => [
                    'label' => 'Documentacao',
                    'permissions' => DocumentationPermissions::labels(),
                ],
                'diario_obra' => [
                    'label' => 'Diario de Obra',
                    'permissions' => DiarioObraPermissions::labels(),
                ],
                'ordem_servico' => [
                    'label' => 'Ordem de Serviço',
                    'permissions' => OrdemServicoPermissions::labels(),
                ],
                'medicao' => [
                    'label' => 'Medição',
                    'permissions' => MedicaoPermissions::labels(),
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
            'contract_id' => ['nullable', 'integer', Rule::exists('contracts', 'id')->where(fn ($query) => $query->where('tenant_id', $tenant->id))],
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
            'budget_permissions' => ['nullable', 'array'],
            'budget_permissions.*' => ['required', 'string', Rule::in(BudgetPermissions::all())],
            'documentation_permissions' => ['nullable', 'array'],
            'documentation_permissions.*' => ['required', 'string', Rule::in(DocumentationPermissions::all())],
            'diario_obra_permissions' => ['nullable', 'array'],
            'diario_obra_permissions.*' => ['required', 'string', Rule::in(DiarioObraPermissions::all())],
            'ordem_servico_permissions' => ['nullable', 'array'],
            'ordem_servico_permissions.*' => ['required', 'string', Rule::in(OrdemServicoPermissions::all())],
            'medicao_permissions' => ['nullable', 'array'],
            'medicao_permissions.*' => ['required', 'string', Rule::in(MedicaoPermissions::all())],
            'contract_permissions' => ['nullable', 'array'],
            'contract_permissions.*' => ['required', 'string', Rule::in(ContractPermissions::all())],
        ]);

        $membership = $tenant->memberships()
            ->where('user_id', $data['user_id'])
            ->where('status', 'active')
            ->firstOrFail();
        $contract = ! empty($data['contract_id'])
            ? $tenant->contracts()->findOrFail($data['contract_id'])
            : null;

        if ($membership->role === 'tenant_owner') {
            return back()->with('success', 'Owner mantem acesso total automaticamente.');
        }

        $activityPermissions = ActivityPermissions::normalize($data['activity_permissions'] ?? []);
        $projectPermissions = ProjectPermissions::normalize($data['project_permissions'] ?? []);
        $rncPermissions = RncPermissions::normalize($data['rnc_permissions'] ?? []);
        $userPermissions = UserPermissions::normalize($data['user_permissions'] ?? []);
        $parametrizacaoPermissions = ParametrizacaoPermissions::normalize($data['parametrizacao_permissions'] ?? []);
        $budgetPermissions = BudgetPermissions::normalize($data['budget_permissions'] ?? []);
        $documentationPermissions = DocumentationPermissions::normalize($data['documentation_permissions'] ?? []);
        $diarioObraPermissions = DiarioObraPermissions::normalize($data['diario_obra_permissions'] ?? []);
        $ordemServicoPermissions = OrdemServicoPermissions::normalize($data['ordem_servico_permissions'] ?? []);
        $medicaoPermissions = MedicaoPermissions::normalize($data['medicao_permissions'] ?? []);
        $contractPermissions = ContractPermissions::normalize($data['contract_permissions'] ?? []);

        if ($membership->role === 'tenant_admin') {
            $membership->update([
                'activity_permissions' => $activityPermissions,
                'project_permissions' => $projectPermissions,
                'user_permissions' => $userPermissions,
                'parametrizacao_permissions' => $parametrizacaoPermissions,
                'budget_permissions' => $budgetPermissions,
                'documentation_permissions' => $documentationPermissions,
                'diario_obra_permissions' => $diarioObraPermissions,
                'ordem_servico_permissions' => $ordemServicoPermissions,
                'medicao_permissions' => $medicaoPermissions,
                'contract_permissions' => $contractPermissions,
            ]);
        } else {
            if ($contract) {
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
                    'documentation_permissions' => $documentationPermissions,
                    'diario_obra_permissions' => $diarioObraPermissions,
                    'ordem_servico_permissions' => $ordemServicoPermissions,
                    'medicao_permissions' => $medicaoPermissions,
                    'contract_permissions' => $contractPermissions,
                ]);
            } elseif ($activityPermissions !== [] || $projectPermissions !== [] || $rncPermissions !== [] || $documentationPermissions !== [] || $diarioObraPermissions !== [] || $ordemServicoPermissions !== [] || $medicaoPermissions !== [] || $contractPermissions !== []) {
                throw ValidationException::withMessages([
                    'contract_id' => 'Vincule o usuario a um contrato para configurar Atividades, Contratos, Projetos, RNC, Documentacao, Diario de Obra, Ordem de Servico ou Medicao.',
                ]);
            }

            $membership->update([
                'user_permissions' => $userPermissions,
                'parametrizacao_permissions' => $parametrizacaoPermissions,
                'budget_permissions' => $budgetPermissions,
            ]);
        }

        if ($contract) {
            $this->syncRncPermissions($request, $tenant, $contract, $membership, $rncPermissions);
        }

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
            'responsibility_type' => RncPermissions::responsibilityTypeForPermissions($permissions),
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

    private function documentationPermissionsByUserContract($memberships, $contracts, $participants): array
    {
        $matrix = [];

        foreach ($memberships as $membership) {
            foreach ($contracts as $contract) {
                $key = "{$membership->user_id}:{$contract->id}";

                if ($membership->role === 'tenant_owner') {
                    $matrix[$key] = DocumentationPermissions::all();
                    continue;
                }

                if ($membership->role === 'tenant_admin') {
                    $matrix[$key] = DocumentationPermissions::normalize(
                        $membership->documentation_permissions ?? DocumentationPermissions::defaultForRole($membership->role)
                    );
                    continue;
                }

                $participant = $participants
                    ->where('user_id', $membership->user_id)
                    ->where('contract_id', $contract->id)
                    ->first();

                $matrix[$key] = $participant
                    ? DocumentationPermissions::normalize(
                        $participant->documentation_permissions
                            ?? $membership->documentation_permissions
                            ?? DocumentationPermissions::defaultForRole($membership->role)
                    )
                    : [];
            }
        }

        return $matrix;
    }

    private function diarioObraPermissionsByUserContract($memberships, $contracts, $participants): array
    {
        $matrix = [];

        foreach ($memberships as $membership) {
            foreach ($contracts as $contract) {
                $key = "{$membership->user_id}:{$contract->id}";

                if ($membership->role === 'tenant_owner') {
                    $matrix[$key] = DiarioObraPermissions::all();
                    continue;
                }

                if ($membership->role === 'tenant_admin') {
                    $matrix[$key] = DiarioObraPermissions::normalize(
                        $membership->diario_obra_permissions ?? DiarioObraPermissions::defaultForRole($membership->role)
                    );
                    continue;
                }

                $participant = $participants
                    ->where('user_id', $membership->user_id)
                    ->where('contract_id', $contract->id)
                    ->first();

                $matrix[$key] = $participant
                    ? DiarioObraPermissions::normalize(
                        $participant->diario_obra_permissions
                            ?? $membership->diario_obra_permissions
                            ?? DiarioObraPermissions::defaultForRole($membership->role)
                    )
                    : [];
            }
        }

        return $matrix;
    }

    private function ordemServicoPermissionsByUserContract($memberships, $contracts, $participants): array
    {
        $matrix = [];

        foreach ($memberships as $membership) {
            foreach ($contracts as $contract) {
                $key = "{$membership->user_id}:{$contract->id}";

                if ($membership->role === 'tenant_owner') {
                    $matrix[$key] = OrdemServicoPermissions::all();
                    continue;
                }

                if ($membership->role === 'tenant_admin') {
                    $matrix[$key] = OrdemServicoPermissions::normalize(
                        $membership->ordem_servico_permissions ?? OrdemServicoPermissions::defaultForRole($membership->role)
                    );
                    continue;
                }

                $participant = $participants
                    ->where('user_id', $membership->user_id)
                    ->where('contract_id', $contract->id)
                    ->first();

                $matrix[$key] = $participant
                    ? OrdemServicoPermissions::normalize(
                        $participant->ordem_servico_permissions
                            ?? $membership->ordem_servico_permissions
                            ?? OrdemServicoPermissions::defaultForRole($membership->role)
                    )
                    : [];
            }
        }

        return $matrix;
    }

    private function medicaoPermissionsByUserContract($memberships, $contracts, $participants): array
    {
        $matrix = [];

        foreach ($memberships as $membership) {
            foreach ($contracts as $contract) {
                $key = "{$membership->user_id}:{$contract->id}";

                if ($membership->role === 'tenant_owner') {
                    $matrix[$key] = MedicaoPermissions::all();
                    continue;
                }

                if ($membership->role === 'tenant_admin') {
                    $matrix[$key] = MedicaoPermissions::normalize(
                        $membership->medicao_permissions ?? MedicaoPermissions::defaultForRole($membership->role)
                    );
                    continue;
                }

                $participant = $participants
                    ->where('user_id', $membership->user_id)
                    ->where('contract_id', $contract->id)
                    ->first();

                $matrix[$key] = $participant
                    ? MedicaoPermissions::normalize(
                        $participant->medicao_permissions
                            ?? $membership->medicao_permissions
                            ?? MedicaoPermissions::defaultForRole($membership->role)
                    )
                    : [];
            }
        }

        return $matrix;
    }

    private function contractPermissionsByUserContract($memberships, $contracts, $participants): array
    {
        $matrix = [];

        foreach ($memberships as $membership) {
            foreach ($contracts as $contract) {
                $key = "{$membership->user_id}:{$contract->id}";

                if ($membership->role === 'tenant_owner') {
                    $matrix[$key] = ContractPermissions::all();
                    continue;
                }

                if ($membership->role === 'tenant_admin') {
                    $matrix[$key] = ContractPermissions::normalize(
                        $membership->contract_permissions ?? ContractPermissions::defaultForRole($membership->role)
                    );
                    continue;
                }

                $participant = $participants
                    ->where('user_id', $membership->user_id)
                    ->where('contract_id', $contract->id)
                    ->first();

                $matrix[$key] = $participant
                    ? ContractPermissions::normalize(
                        $participant->contract_permissions
                            ?? $membership->contract_permissions
                            ?? ContractPermissions::defaultForRole($membership->role)
                    )
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
