<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Contract;
use App\Models\Tenant;
use App\Support\ActivityPermissions;
use App\Support\ProjectPermissions;
use App\Support\RncPermissions;
use App\Support\TenantRoles;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ContractController extends Controller
{
    private const BRAZILIAN_STATES = [
        'AC', 'AL', 'AP', 'AM', 'BA', 'CE', 'DF', 'ES', 'GO',
        'MA', 'MT', 'MS', 'MG', 'PA', 'PB', 'PR', 'PE', 'PI',
        'RJ', 'RN', 'RS', 'RO', 'RR', 'SC', 'SP', 'SE', 'TO',
    ];

    public function index(Request $request, Tenant $tenant): Response
    {
        $contracts = $this->accessibleContracts($request, $tenant);

        return Inertia::render('Tenant/Contracts/Index', [
            'tenant' => $tenant,
            'contracts' => $contracts
                ->with(['obra', 'clienteEmpresa', 'construtoraEmpresa', 'gerenciadoraEmpresa'])
                ->withCount([
                    'participants',
                    'activities as open_activities_count' => fn (Builder $query): Builder => $query->where('status', '!=', 'done'),
                    'activities as overdue_activities_count' => fn (Builder $query): Builder => $query
                        ->where('status', '!=', 'done')
                        ->whereDate('due_date', '<', today()),
                    'relatorioNaoConformidades as open_rncs_count' => fn (Builder $query): Builder => $query->where('status', 'aberta'),
                    'projectDocuments as pending_projects_count' => fn (Builder $query): Builder => $query->whereIn('status', ['em_analise', 'em_aprovacao']),
                ])
                ->latest()
                ->get(),
            'statuses' => ['planning', 'active', 'paused', 'completed', 'cancelled'],
            'canCreateContracts' => TenantRoles::canManageContracts($request->user()->tenantRole($tenant)),
        ]);
    }

    public function store(Request $request, Tenant $tenant): RedirectResponse
    {
        $this->authorizeManageContracts($request, $tenant);

        $request->merge([
            'code' => mb_strtoupper((string) $request->input('code', '')),
            'city' => trim((string) $request->input('city', '')),
            'state' => mb_strtoupper((string) $request->input('state', '')),
        ]);

        $data = $request->validate([
            'code' => ['required', 'string', 'max:50', Rule::unique('contracts', 'code')->where('tenant_id', $tenant->id)],
            'total_value' => ['required', 'numeric', 'min:0'],
            'currency' => ['required', 'string', Rule::in(['BRL', 'USD', 'JPY', 'CNY', 'EUR'])],
            'city' => ['required', 'string', 'max:120'],
            'state' => ['required', 'string', Rule::in(self::BRAZILIAN_STATES)],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after_or_equal:starts_at'],
        ]);

        $contract = $tenant->contracts()->create([
            ...$data,
            'name' => 'Contrato '.$data['code'],
            'status' => 'planning',
        ]);

        $contract->participants()->updateOrCreate(
            [
                'user_id' => $request->user()->id,
                'side' => 'manager',
            ],
            [
                'tenant_id' => $tenant->id,
                'role' => 'manager',
                'status' => 'active',
                'joined_at' => now(),
            ],
        );

        return redirect()->route('tenant.contracts.show', [$tenant, $contract])->with('success', 'Contrato criado.');
    }

    public function show(Request $request, Tenant $tenant, Contract $contract): Response
    {
        abort_unless((int) $contract->tenant_id === (int) $tenant->id, 404);
        abort_unless($this->canAccessContract($request, $tenant, $contract), 403);

        $contract->load([
            'participants.user',
            'obra',
            'clienteEmpresa',
            'construtoraEmpresa',
            'gerenciadoraEmpresa',
        ])->loadCount([
            'activities as open_activities_count' => fn (Builder $query): Builder => $query->where('status', '!=', 'done'),
            'activities as overdue_activities_count' => fn (Builder $query): Builder => $query
                ->where('status', '!=', 'done')
                ->whereDate('due_date', '<', today()),
            'relatorioNaoConformidades as open_rncs_count' => fn (Builder $query): Builder => $query->where('status', 'aberta'),
            'projectDocuments as pending_projects_count' => fn (Builder $query): Builder => $query->whereIn('status', ['em_analise', 'em_aprovacao']),
            'projectDocuments as approved_projects_count' => fn (Builder $query): Builder => $query->where('status', 'ativo'),
        ]);

        return Inertia::render('Tenant/Contracts/Show', [
            'tenant' => $tenant,
            'contract' => $contract,
            'recentActivities' => $contract->activities()
                ->with('assignees:id,name,avatar_url')
                ->latest()
                ->limit(5)
                ->get(['id', 'contract_id', 'title', 'category', 'status', 'priority', 'due_date', 'created_at']),
            'recentRncs' => $contract->relatorioNaoConformidades()
                ->with('disciplina:id,nome,sigla')
                ->latest()
                ->limit(5)
                ->get(['id', 'contract_id', 'disciplina_id', 'sequence_number', 'sequence_year', 'gravidade', 'status', 'opened_at']),
            'recentProjects' => $contract->projectDocuments()
                ->with([
                    'disciplina:id,nome,sigla',
                    'latestVersion' => fn ($query) => $query->select([
                        'project_document_versions.id',
                        'project_document_versions.project_document_id',
                        'project_document_versions.revision',
                        'project_document_versions.derivative_status',
                    ]),
                ])
                ->latest()
                ->limit(5)
                ->get(['id', 'contract_id', 'disciplina_id', 'title', 'code', 'status', 'created_at']),
            'canManageParticipants' => $this->canManageParticipants($request, $tenant, $contract),
            'capabilities' => [
                'viewActivities' => ActivityPermissions::can($request->user(), $tenant, ActivityPermissions::VIEW, $contract),
                'createActivity' => ActivityPermissions::can($request->user(), $tenant, ActivityPermissions::CREATE, $contract),
                'viewProjects' => ProjectPermissions::can($request->user(), $tenant, ProjectPermissions::VIEW, $contract),
                'uploadProject' => ProjectPermissions::can($request->user(), $tenant, ProjectPermissions::UPLOAD, $contract),
                'viewRncs' => RncPermissions::can($request->user(), $tenant, RncPermissions::VIEW, $contract),
                'createRnc' => RncPermissions::can($request->user(), $tenant, RncPermissions::CREATE, $contract),
            ],
            'participantRoles' => [
                'client' => ['client_approver', 'client_viewer'],
                'contractor' => ['contractor_lead', 'contractor_member'],
                'manager' => ['manager', 'team_member'],
            ],
        ]);
    }

    private function accessibleContracts(Request $request, Tenant $tenant)
    {
        $query = $tenant->contracts();
        $tenantRole = $request->user()->tenantRole($tenant);

        if (! $request->user()->is_platform_admin && ! in_array($tenantRole, ['tenant_owner', 'tenant_admin'], true)) {
            $query->whereHas('participants', function ($query) use ($request): void {
                $query->where('user_id', $request->user()->id)->where('status', 'active');
            });
        }

        return $query;
    }

    private function canAccessContract(Request $request, Tenant $tenant, Contract $contract): bool
    {
        if ($request->user()->is_platform_admin || in_array($request->user()->tenantRole($tenant), ['tenant_owner', 'tenant_admin'], true)) {
            return true;
        }

        return $contract->participants()
            ->where('user_id', $request->user()->id)
            ->where('status', 'active')
            ->exists();
    }

    private function authorizeManageContracts(Request $request, Tenant $tenant): void
    {
        abort_unless(
            TenantRoles::canManageContracts($request->user()->tenantRole($tenant)),
            403,
        );
    }

    private function canManageParticipants(Request $request, Tenant $tenant, Contract $contract): bool
    {
        if (in_array($request->user()->tenantRole($tenant), ['tenant_owner', 'tenant_admin'], true)) {
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
