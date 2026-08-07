<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\ContractParticipant;
use App\Models\AiMonthlyUsage;
use App\Models\RelatorioNaoConformidadeResponsavel;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Notifications\UserTemporaryPasswordNotification;
use App\Support\ActivityPermissions;
use App\Support\BudgetPermissions;
use App\Support\ContractPermissions;
use App\Support\DiarioObraPermissions;
use App\Support\DocumentationPermissions;
use App\Support\MedicaoPermissions;
use App\Support\OrdemServicoPermissions;
use App\Support\ParametrizacaoPermissions;
use App\Support\PasswordPolicy;
use App\Support\ProjectPermissions;
use App\Support\RncPermissions;
use App\Support\TenantRoles;
use App\Support\UserPermissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    private const CONTRACT_ROLES_BY_SIDE = [
        'manager' => ['manager', 'team_member'],
        'client' => ['client_approver', 'client_viewer'],
        'contractor' => ['contractor_lead', 'contractor_member'],
    ];

    public function index(Tenant $tenant): Response
    {
        $this->authorizeTenantUsers($tenant);

        $memberships = $tenant->memberships()
            ->with(['user', 'empresa.tipoEmpresa', 'empresa.contract'])
            ->latest()
            ->get();
        $participantsByUser = ContractParticipant::query()
            ->where('tenant_id', $tenant->id)
            ->where('status', 'active')
            ->get()
            ->groupBy('user_id');
        $rncPermissionsByUserContract = RelatorioNaoConformidadeResponsavel::query()
            ->where('tenant_id', $tenant->id)
            ->where('status', 'active')
            ->get()
            ->keyBy(fn (RelatorioNaoConformidadeResponsavel $responsavel): string => "{$responsavel->user_id}:{$responsavel->contract_id}");
        $aiUsageByUser = AiMonthlyUsage::query()
            ->where('tenant_id', $tenant->id)
            ->whereDate('period', now()->startOfMonth()->toDateString())
            ->pluck('total_tokens', 'user_id');

        $memberships->each(function (TenantUser $membership) use ($participantsByUser, $rncPermissionsByUserContract, $aiUsageByUser): void {
            $userPermissions = $membership->role === 'tenant_owner'
                ? UserPermissions::all()
                : UserPermissions::normalize(
                    $membership->user_permissions ?? UserPermissions::defaultForRole($membership->role),
                );

            $membership->setAttribute('user_permissions', $userPermissions);
            $membership->setAttribute('ai_tokens_used_current_month', (int) ($aiUsageByUser[$membership->user_id] ?? 0));
            $membership->setAttribute(
                'parametrizacao_permissions',
                $membership->role === 'tenant_owner'
                    ? ParametrizacaoPermissions::all()
                    : ParametrizacaoPermissions::normalize(
                        $membership->parametrizacao_permissions ?? ParametrizacaoPermissions::defaultForRole($membership->role),
                    ),
            );
            $membership->setAttribute(
                'budget_permissions',
                $membership->role === 'tenant_owner'
                    ? BudgetPermissions::all()
                    : BudgetPermissions::normalize(
                        $membership->budget_permissions ?? BudgetPermissions::defaultForRole($membership->role),
                    ),
            );
            $membership->setAttribute(
                'contract_accesses',
                $participantsByUser
                    ->get($membership->user_id, collect())
                    ->map(fn (ContractParticipant $participant): array => [
                        'contract_id' => (int) $participant->contract_id,
                        'side' => $participant->side,
                        'role' => $participant->role,
                        'activity_permissions' => ActivityPermissions::normalize($participant->activity_permissions ?? []),
                        'contract_permissions' => ContractPermissions::normalize($participant->contract_permissions ?? []),
                        'project_permissions' => ProjectPermissions::normalize($participant->project_permissions ?? []),
                        'documentation_permissions' => DocumentationPermissions::normalize($participant->documentation_permissions ?? []),
                        'diario_obra_permissions' => DiarioObraPermissions::normalize($participant->diario_obra_permissions ?? []),
                        'ordem_servico_permissions' => OrdemServicoPermissions::normalize($participant->ordem_servico_permissions ?? []),
                        'medicao_permissions' => MedicaoPermissions::normalize($participant->medicao_permissions ?? []),
                        'rnc_permissions' => RncPermissions::normalize(
                            $rncPermissionsByUserContract
                                ->get("{$membership->user_id}:{$participant->contract_id}")
                                ?->permissions ?? [],
                        ),
                    ])
                    ->values()
                    ->all(),
            );
        });

        return Inertia::render('Tenant/Users/Index', [
            'tenant' => $tenant,
            'memberships' => $memberships,
            'empresas' => $tenant->empresas()
                ->with(['tipoEmpresa', 'contract'])
                ->orderBy('nome')
                ->get(),
            'contracts' => $tenant->contracts()
                ->with('obra:id,nome')
                ->orderBy('code')
                ->get()
                ->map(fn ($contract): array => [
                    'id' => $contract->id,
                    'code' => $contract->code,
                    'name' => $contract->obra?->nome ?? $contract->name,
                    'status' => $contract->status,
                ])
                ->values(),
            'roles' => TenantRoles::all(),
            'roleGroups' => TenantRoles::groups(),
            'roleLabels' => TenantRoles::labels(),
            'defaultRole' => TenantRoles::defaultRole(),
            'userPermissionOptions' => UserPermissions::labels(),
            'parametrizacaoPermissionOptions' => ParametrizacaoPermissions::labels(),
            'budgetPermissionOptions' => BudgetPermissions::labels(),
            'contractPermissionGroups' => [
                'activity_permissions' => [
                    'label' => 'Atividades',
                    'permissions' => ActivityPermissions::labels(),
                ],
                'contract_permissions' => [
                    'label' => 'Contratos',
                    'permissions' => ContractPermissions::labels(),
                ],
                'project_permissions' => [
                    'label' => 'Projetos',
                    'permissions' => ProjectPermissions::labels(),
                ],
                'rnc_permissions' => [
                    'label' => 'RNC',
                    'permissions' => RncPermissions::labels(),
                ],
                'documentation_permissions' => [
                    'label' => 'Documentação',
                    'permissions' => DocumentationPermissions::labels(),
                ],
                'diario_obra_permissions' => [
                    'label' => 'Diário de Obra',
                    'permissions' => DiarioObraPermissions::labels(),
                ],
                'ordem_servico_permissions' => [
                    'label' => 'Ordem de Serviço',
                    'permissions' => OrdemServicoPermissions::labels(),
                ],
                'medicao_permissions' => [
                    'label' => 'Medição',
                    'permissions' => MedicaoPermissions::labels(),
                ],
            ],
            'contractRolesBySide' => self::CONTRACT_ROLES_BY_SIDE,
            'userPermissionCan' => [
                'create_user' => UserPermissions::can(request()->user(), $tenant, UserPermissions::CREATE),
                'edit_user' => UserPermissions::can(request()->user(), $tenant, UserPermissions::EDIT),
                'deactivate_user' => UserPermissions::can(request()->user(), $tenant, UserPermissions::DEACTIVATE),
            ],
            'defaultAiUserTokenLimit' => (int) config('services.openai.user_monthly_token_limit'),
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
            'role' => ['required', Rule::in(TenantRoles::all())],
            'ai_monthly_token_limit' => ['nullable', 'integer', 'min:1000', 'max:'.max(1000, (int) config('services.openai.user_monthly_token_limit'))],
            'user_permissions' => ['nullable', 'array'],
            'user_permissions.*' => ['required', 'string', Rule::in(UserPermissions::all())],
            'parametrizacao_permissions' => ['nullable', 'array'],
            'parametrizacao_permissions.*' => ['required', 'string', Rule::in(ParametrizacaoPermissions::all())],
            'budget_permissions' => ['nullable', 'array'],
            'budget_permissions.*' => ['required', 'string', Rule::in(BudgetPermissions::all())],
            'contract_accesses' => ['nullable', 'array'],
            'contract_accesses.*.contract_id' => [
                'required',
                'integer',
                Rule::exists('contracts', 'id')->where(fn ($query) => $query->where('tenant_id', $tenant->id)),
            ],
            'contract_accesses.*.side' => ['required', Rule::in(array_keys(self::CONTRACT_ROLES_BY_SIDE))],
            'contract_accesses.*.role' => ['required', 'string'],
            'contract_accesses.*.activity_permissions' => ['nullable', 'array'],
            'contract_accesses.*.activity_permissions.*' => ['required', 'string', Rule::in(ActivityPermissions::all())],
            'contract_accesses.*.contract_permissions' => ['nullable', 'array'],
            'contract_accesses.*.contract_permissions.*' => ['required', 'string', Rule::in(ContractPermissions::all())],
            'contract_accesses.*.project_permissions' => ['nullable', 'array'],
            'contract_accesses.*.project_permissions.*' => ['required', 'string', Rule::in(ProjectPermissions::all())],
            'contract_accesses.*.rnc_permissions' => ['nullable', 'array'],
            'contract_accesses.*.rnc_permissions.*' => ['required', 'string', Rule::in(RncPermissions::all())],
            'contract_accesses.*.documentation_permissions' => ['nullable', 'array'],
            'contract_accesses.*.documentation_permissions.*' => ['required', 'string', Rule::in(DocumentationPermissions::all())],
            'contract_accesses.*.diario_obra_permissions' => ['nullable', 'array'],
            'contract_accesses.*.diario_obra_permissions.*' => ['required', 'string', Rule::in(DiarioObraPermissions::all())],
            'contract_accesses.*.ordem_servico_permissions' => ['nullable', 'array'],
            'contract_accesses.*.ordem_servico_permissions.*' => ['required', 'string', Rule::in(OrdemServicoPermissions::all())],
            'contract_accesses.*.medicao_permissions' => ['nullable', 'array'],
            'contract_accesses.*.medicao_permissions.*' => ['required', 'string', Rule::in(MedicaoPermissions::all())],
        ], [
            'empresa_id.required' => 'Selecione a empresa do usuario.',
            'empresa_id.exists' => 'A empresa selecionada nao pertence a este tenant.',
        ]);

        $temporaryPassword = PasswordPolicy::temporaryPassword();

        $user = User::firstOrCreate(
            ['email' => mb_strtolower($data['email'])],
            [
                'name' => $data['name'],
                'password' => Hash::make($temporaryPassword),
                'email_verified_at' => now(),
                'must_change_password' => true,
                'temporary_password_created_at' => now(),
            ],
        );

        DB::transaction(function () use ($tenant, $user, $data, $request): void {
            $firstContractAccess = collect($data['contract_accesses'] ?? [])->first() ?? [];

            $tenant->memberships()->updateOrCreate(
                ['user_id' => $user->id],
                [
                    'empresa_id' => $data['empresa_id'],
                    'role' => $data['role'],
                    'status' => 'active',
                    'ai_monthly_token_limit' => $data['ai_monthly_token_limit'] ?? null,
                    'user_permissions' => UserPermissions::normalize(
                        $data['user_permissions'] ?? UserPermissions::defaultForRole($data['role']),
                    ),
                    'parametrizacao_permissions' => ParametrizacaoPermissions::normalize(
                        $data['parametrizacao_permissions'] ?? ParametrizacaoPermissions::defaultForRole($data['role']),
                    ),
                    'budget_permissions' => BudgetPermissions::normalize(
                        $data['budget_permissions'] ?? BudgetPermissions::defaultForRole($data['role']),
                    ),
                    'activity_permissions' => ActivityPermissions::normalize(
                        $firstContractAccess['activity_permissions'] ?? ActivityPermissions::defaultForRole($data['role']),
                    ),
                    'contract_permissions' => ContractPermissions::normalize(
                        $firstContractAccess['contract_permissions'] ?? ContractPermissions::defaultForRole($data['role']),
                    ),
                    'project_permissions' => ProjectPermissions::normalize(
                        $firstContractAccess['project_permissions'] ?? ProjectPermissions::defaultForRole($data['role']),
                    ),
                    'documentation_permissions' => DocumentationPermissions::normalize(
                        $firstContractAccess['documentation_permissions'] ?? DocumentationPermissions::defaultForRole($data['role']),
                    ),
                    'diario_obra_permissions' => DiarioObraPermissions::normalize(
                        $firstContractAccess['diario_obra_permissions'] ?? DiarioObraPermissions::defaultForRole($data['role']),
                    ),
                    'ordem_servico_permissions' => OrdemServicoPermissions::normalize(
                        $firstContractAccess['ordem_servico_permissions'] ?? OrdemServicoPermissions::defaultForRole($data['role']),
                    ),
                    'medicao_permissions' => MedicaoPermissions::normalize(
                        $firstContractAccess['medicao_permissions'] ?? MedicaoPermissions::defaultForRole($data['role']),
                    ),
                    'invited_at' => now(),
                    'joined_at' => now(),
                ],
            );

            $this->syncContractAccesses($request, $tenant, $user, $data['contract_accesses'] ?? []);
        });

        if ($user->wasRecentlyCreated) {
            $user->notify(new UserTemporaryPasswordNotification($tenant, $temporaryPassword));

            return back()->with('success', 'Usuario criado e senha provisoria enviada por email.');
        }

        return back()->with('success', 'Usuario existente vinculado ao tenant.');
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
            'role' => ['required', Rule::in(TenantRoles::all())],
            'ai_monthly_token_limit' => ['nullable', 'integer', 'min:1000', 'max:'.max(1000, (int) config('services.openai.user_monthly_token_limit'))],
            'user_permissions' => ['nullable', 'array'],
            'user_permissions.*' => ['required', 'string', Rule::in(UserPermissions::all())],
            'parametrizacao_permissions' => ['nullable', 'array'],
            'parametrizacao_permissions.*' => ['required', 'string', Rule::in(ParametrizacaoPermissions::all())],
            'budget_permissions' => ['nullable', 'array'],
            'budget_permissions.*' => ['required', 'string', Rule::in(BudgetPermissions::all())],
            'contract_accesses' => ['nullable', 'array'],
            'contract_accesses.*.contract_id' => [
                'required',
                'integer',
                Rule::exists('contracts', 'id')->where(fn ($query) => $query->where('tenant_id', $tenant->id)),
            ],
            'contract_accesses.*.side' => ['required', Rule::in(array_keys(self::CONTRACT_ROLES_BY_SIDE))],
            'contract_accesses.*.role' => ['required', 'string'],
            'contract_accesses.*.activity_permissions' => ['nullable', 'array'],
            'contract_accesses.*.activity_permissions.*' => ['required', 'string', Rule::in(ActivityPermissions::all())],
            'contract_accesses.*.contract_permissions' => ['nullable', 'array'],
            'contract_accesses.*.contract_permissions.*' => ['required', 'string', Rule::in(ContractPermissions::all())],
            'contract_accesses.*.project_permissions' => ['nullable', 'array'],
            'contract_accesses.*.project_permissions.*' => ['required', 'string', Rule::in(ProjectPermissions::all())],
            'contract_accesses.*.rnc_permissions' => ['nullable', 'array'],
            'contract_accesses.*.rnc_permissions.*' => ['required', 'string', Rule::in(RncPermissions::all())],
            'contract_accesses.*.documentation_permissions' => ['nullable', 'array'],
            'contract_accesses.*.documentation_permissions.*' => ['required', 'string', Rule::in(DocumentationPermissions::all())],
            'contract_accesses.*.diario_obra_permissions' => ['nullable', 'array'],
            'contract_accesses.*.diario_obra_permissions.*' => ['required', 'string', Rule::in(DiarioObraPermissions::all())],
            'contract_accesses.*.ordem_servico_permissions' => ['nullable', 'array'],
            'contract_accesses.*.ordem_servico_permissions.*' => ['required', 'string', Rule::in(OrdemServicoPermissions::all())],
            'contract_accesses.*.medicao_permissions' => ['nullable', 'array'],
            'contract_accesses.*.medicao_permissions.*' => ['required', 'string', Rule::in(MedicaoPermissions::all())],
        ], [
            'empresa_id.required' => 'Selecione a empresa do usuario.',
            'empresa_id.exists' => 'A empresa selecionada nao pertence a este tenant.',
        ]);

        DB::transaction(function () use ($request, $tenant, $membership, $data): void {
            $membership->user->update([
                'name' => $data['name'],
                'email' => mb_strtolower($data['email']),
            ]);

            $membershipData = [
                'empresa_id' => $data['empresa_id'],
                'role' => $data['role'],
                'ai_monthly_token_limit' => $data['ai_monthly_token_limit'] ?? null,
            ];

            if (array_key_exists('user_permissions', $data)) {
                $membershipData['user_permissions'] = UserPermissions::normalize($data['user_permissions'] ?? []);
            }

            if (array_key_exists('parametrizacao_permissions', $data)) {
                $membershipData['parametrizacao_permissions'] = ParametrizacaoPermissions::normalize($data['parametrizacao_permissions'] ?? []);
            }

            if (array_key_exists('budget_permissions', $data)) {
                $membershipData['budget_permissions'] = BudgetPermissions::normalize($data['budget_permissions'] ?? []);
            }

            $membership->update($membershipData);

            if (array_key_exists('contract_accesses', $data)) {
                $this->syncContractAccesses(
                    $request,
                    $tenant,
                    $membership->user,
                    $data['contract_accesses'] ?? [],
                    true,
                );
            }
        });

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

    public function resetPassword(Request $request, Tenant $tenant, TenantUser $membership): RedirectResponse
    {
        $this->authorizeTenantUsers($tenant, UserPermissions::EDIT);
        $this->ensureMembershipBelongsToTenant($tenant, $membership);

        abort_if($membership->user_id === $request->user()->id, 422, 'Voce nao pode resetar a propria senha por aqui.');

        $temporaryPassword = PasswordPolicy::temporaryPassword();

        $membership->user->update([
            'password' => Hash::make($temporaryPassword),
            'must_change_password' => true,
            'temporary_password_created_at' => now(),
        ]);

        return back()
            ->with('success', 'Senha provisoria gerada. Copie e envie ao usuario por um canal seguro.')
            ->with('reset_password', [
                'user_name' => $membership->user->name,
                'user_email' => $membership->user->email,
                'temporary_password' => $temporaryPassword,
            ]);
    }

    private function authorizeTenantUsers(Tenant $tenant, string $permission = UserPermissions::VIEW): void
    {
        abort_unless(UserPermissions::can(request()->user(), $tenant, $permission), 403);
    }

    private function ensureMembershipBelongsToTenant(Tenant $tenant, TenantUser $membership): void
    {
        abort_unless($membership->tenant_id === $tenant->id, 404);
    }

    private function syncContractAccesses(
        Request $request,
        Tenant $tenant,
        User $user,
        array $accesses,
        bool $removeMissing = false
    ): void
    {
        if ($removeMissing) {
            $selectedKeys = collect($accesses)
                ->map(fn (array $access): string => "{$access['contract_id']}:{$access['side']}")
                ->all();
            $selectedContractIds = collect($accesses)
                ->pluck('contract_id')
                ->map(fn ($id): int => (int) $id)
                ->unique()
                ->values();

            ContractParticipant::query()
                ->where('tenant_id', $tenant->id)
                ->where('user_id', $user->id)
                ->where('status', 'active')
                ->get()
                ->reject(fn (ContractParticipant $participant): bool => in_array(
                    "{$participant->contract_id}:{$participant->side}",
                    $selectedKeys,
                    true,
                ))
                ->each(function (ContractParticipant $participant): void {
                    $participant->update(['status' => 'inactive']);
                    $participant->delete();
                });

            $rncLinksToRemove = RelatorioNaoConformidadeResponsavel::query()
                ->where('tenant_id', $tenant->id)
                ->where('user_id', $user->id)
                ->where('status', 'active');

            if ($selectedContractIds->isNotEmpty()) {
                $rncLinksToRemove->whereNotIn('contract_id', $selectedContractIds);
            }

            $rncLinksToRemove
                ->get()
                ->each(function (RelatorioNaoConformidadeResponsavel $link): void {
                    $link->update([
                        'status' => 'inactive',
                        'permissions' => [],
                    ]);
                    $link->delete();
                });
        }

        foreach ($accesses as $access) {
            $side = $access['side'];
            $role = $access['role'];

            abort_unless(in_array($role, self::CONTRACT_ROLES_BY_SIDE[$side] ?? [], true), 422);

            $participant = ContractParticipant::withTrashed()->firstOrNew([
                'tenant_id' => $tenant->id,
                'contract_id' => $access['contract_id'],
                'user_id' => $user->id,
                'side' => $side,
            ]);

            $participant->fill([
                'role' => $role,
                'status' => 'active',
                'activity_permissions' => ActivityPermissions::normalize($access['activity_permissions'] ?? []),
                'contract_permissions' => ContractPermissions::normalize($access['contract_permissions'] ?? []),
                'project_permissions' => ProjectPermissions::normalize($access['project_permissions'] ?? []),
                'documentation_permissions' => DocumentationPermissions::normalize($access['documentation_permissions'] ?? []),
                'diario_obra_permissions' => DiarioObraPermissions::normalize($access['diario_obra_permissions'] ?? []),
                'ordem_servico_permissions' => OrdemServicoPermissions::normalize($access['ordem_servico_permissions'] ?? []),
                'medicao_permissions' => MedicaoPermissions::normalize($access['medicao_permissions'] ?? []),
                'invited_at' => now(),
                'joined_at' => now(),
            ]);

            $participant->save();

            if ($participant->trashed()) {
                $participant->restore();
            }

            $this->syncRncPermissions($request, $tenant, $user, (int) $access['contract_id'], RncPermissions::normalize($access['rnc_permissions'] ?? []));
        }
    }

    private function syncRncPermissions(Request $request, Tenant $tenant, User $user, int $contractId, array $permissions): void
    {
        $link = RelatorioNaoConformidadeResponsavel::withTrashed()
            ->where('tenant_id', $tenant->id)
            ->where('contract_id', $contractId)
            ->where('user_id', $user->id)
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
            'contract_id' => $contractId,
            'user_id' => $user->id,
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

}
