<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Contract;
use App\Models\Disciplina;
use App\Models\ProjectDisciplineResponsavel;
use App\Models\Tenant;
use App\Models\User;
use App\Support\ProjectPermissions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ProjectResponsavelController extends Controller
{
    private const TIPOS = [
        'analise' => 'Analise da disciplina',
        'aprovacao' => 'Aprovacao final',
    ];

    public function index(Request $request, Tenant $tenant): Response
    {
        abort_unless(ProjectPermissions::canAny($request->user(), $tenant, ProjectPermissions::RESPONSIBLES), 403);

        $contractIds = ProjectPermissions::contractIdsFor($request->user(), $tenant, ProjectPermissions::RESPONSIBLES);
        $contracts = $tenant->contracts()
            ->when($contractIds !== null, fn ($query) => $query->whereIn('id', $contractIds))
            ->with('obra:id,nome')
            ->orderBy('code')
            ->get();

        return Inertia::render('Tenant/Projects/Responsaveis', [
            'tenant' => $tenant,
            'contracts' => $contracts->map(fn (Contract $contract): array => [
                'id' => $contract->id,
                'code' => $contract->code,
                'name' => $contract->obra?->nome ?? $contract->name,
                'status' => $contract->status,
            ])->values(),
            'disciplinasByContract' => $this->disciplinasByContract($tenant, $contracts),
            'usersByContract' => $this->candidateUsersByContract($tenant, $contracts),
            'tipos' => self::TIPOS,
            'responsaveis' => $tenant->projectDisciplineResponsaveis()
                ->where('status', 'active')
                ->with([
                    'user:id,name,email,avatar_url',
                    'contract:id,code,name,obra_id',
                    'contract.obra:id,nome',
                    'disciplina:id,nome,sigla,cor',
                ])
                ->latest()
                ->get()
                ->map(fn (ProjectDisciplineResponsavel $responsavel): array => [
                    'id' => $responsavel->id,
                    'user' => $responsavel->user,
                    'contract' => [
                        'id' => $responsavel->contract?->id,
                        'code' => $responsavel->contract?->code,
                        'name' => $responsavel->contract?->obra?->nome ?? $responsavel->contract?->name,
                    ],
                    'disciplina' => $responsavel->disciplina,
                    'tipo' => $responsavel->tipo,
                    'tipo_label' => self::TIPOS[$responsavel->tipo] ?? $responsavel->tipo,
                    'created_at' => $responsavel->created_at?->format('d/m/Y H:i'),
                ])
                ->values(),
        ]);
    }

    public function store(Request $request, Tenant $tenant): RedirectResponse
    {
        abort_unless(ProjectPermissions::canAny($request->user(), $tenant, ProjectPermissions::RESPONSIBLES), 403);

        $data = $request->validate([
            'contract_id' => [
                'required',
                Rule::exists('contracts', 'id')->where(fn ($query) => $query->where('tenant_id', $tenant->id)),
            ],
            'disciplina_ids' => ['required', 'array', 'min:1'],
            'disciplina_ids.*' => [
                'required',
                'integer',
                'distinct',
                Rule::exists('disciplinas', 'id')->where(fn ($query) => $query->where('tenant_id', $tenant->id)),
            ],
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'tipo' => ['required', Rule::in(array_keys(self::TIPOS))],
        ], [
            'contract_id.required' => 'Selecione o contrato.',
            'disciplina_ids.required' => 'Selecione ao menos uma disciplina.',
            'disciplina_ids.min' => 'Selecione ao menos uma disciplina.',
            'disciplina_ids.*.exists' => 'Uma das disciplinas selecionadas nao pertence a este tenant.',
            'user_id.required' => 'Selecione o usuario.',
            'tipo.required' => 'Selecione o tipo de responsabilidade.',
        ]);

        $contract = $tenant->contracts()->findOrFail($data['contract_id']);
        $disciplinaIds = collect($data['disciplina_ids'])->map(fn ($id): int => (int) $id)->unique()->values();
        $disciplinas = $tenant->disciplinas()->whereIn('id', $disciplinaIds)->get();
        $user = User::findOrFail($data['user_id']);

        abort_unless(ProjectPermissions::can($request->user(), $tenant, ProjectPermissions::RESPONSIBLES, $contract), 403);

        if ($disciplinas->count() !== $disciplinaIds->count() || $disciplinas->contains(fn (Disciplina $disciplina): bool => (int) $disciplina->contract_id !== (int) $contract->id)) {
            throw ValidationException::withMessages([
                'disciplina_ids' => 'Selecione apenas disciplinas vinculadas ao contrato escolhido.',
            ]);
        }

        if (! $this->canAccessContract($user, $tenant, $contract)) {
            throw ValidationException::withMessages([
                'user_id' => 'Selecione um usuario com acesso ativo a este contrato.',
            ]);
        }

        DB::transaction(function () use ($tenant, $contract, $disciplinas, $user, $request, $data): void {
            $disciplinas->each(function (Disciplina $disciplina) use ($tenant, $contract, $user, $request, $data): void {
                $responsavel = ProjectDisciplineResponsavel::withTrashed()
                    ->where('contract_id', $contract->id)
                    ->where('disciplina_id', $disciplina->id)
                    ->where('user_id', $user->id)
                    ->where('tipo', $data['tipo'])
                    ->firstOrNew([
                        'contract_id' => $contract->id,
                        'disciplina_id' => $disciplina->id,
                        'user_id' => $user->id,
                        'tipo' => $data['tipo'],
                    ]);

                $responsavel->fill([
                    'tenant_id' => $tenant->id,
                    'created_by_id' => $request->user()->id,
                    'status' => 'active',
                ]);
                $responsavel->save();

                if ($responsavel->trashed()) {
                    $responsavel->restore();
                }
            });
        });

        return back()->with('success', $disciplinas->count().' disciplina(s) vinculada(s) ao responsavel.');
    }

    public function destroy(Request $request, Tenant $tenant, ProjectDisciplineResponsavel $responsavel): RedirectResponse
    {
        abort_unless(ProjectPermissions::canAny($request->user(), $tenant, ProjectPermissions::RESPONSIBLES), 403);
        abort_unless((int) $responsavel->tenant_id === (int) $tenant->id, 404);
        abort_unless(ProjectPermissions::can($request->user(), $tenant, ProjectPermissions::RESPONSIBLES, $responsavel->contract), 403);

        $responsavel->update(['status' => 'inactive']);
        $responsavel->delete();

        return back()->with('success', 'Responsavel removido da disciplina.');
    }

    /**
     * @param  Collection<int, Contract>  $contracts
     * @return array<int, array<int, array<string, mixed>>>
     */
    private function disciplinasByContract(Tenant $tenant, Collection $contracts): array
    {
        $disciplinas = $tenant->disciplinas()
            ->whereIn('contract_id', $contracts->pluck('id'))
            ->orderBy('nome')
            ->get(['id', 'contract_id', 'nome', 'sigla', 'cor']);

        return $contracts->mapWithKeys(fn (Contract $contract): array => [
            $contract->id => $disciplinas
                ->where('contract_id', $contract->id)
                ->values()
                ->map(fn (Disciplina $disciplina): array => [
                    'id' => $disciplina->id,
                    'contract_id' => $disciplina->contract_id,
                    'nome' => $disciplina->nome,
                    'sigla' => $disciplina->sigla,
                    'cor' => $disciplina->cor,
                ])
                ->all(),
        ])->all();
    }

    /**
     * @param  Collection<int, Contract>  $contracts
     * @return array<int, array<int, array<string, mixed>>>
     */
    private function candidateUsersByContract(Tenant $tenant, Collection $contracts): array
    {
        $globalUsers = $tenant->memberships()
            ->where('status', 'active')
            ->whereIn('role', ['tenant_owner', 'tenant_admin'])
            ->with('user:id,name,email,avatar_url')
            ->get()
            ->pluck('user')
            ->filter();

        return $contracts->mapWithKeys(function (Contract $contract) use ($globalUsers): array {
            $participants = $contract->participants()
                ->where('status', 'active')
                ->with('user:id,name,email,avatar_url')
                ->get()
                ->pluck('user')
                ->filter();

            $users = $globalUsers
                ->merge($participants)
                ->unique('id')
                ->sortBy('name')
                ->values()
                ->map(fn (User $user): array => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'avatar_url' => $user->avatar_url,
                ])
                ->all();

            return [$contract->id => $users];
        })->all();
    }

    private function canAccessContract(User $user, Tenant $tenant, Contract $contract): bool
    {
        if (in_array($user->tenantRole($tenant), ['tenant_owner', 'tenant_admin'], true)) {
            return true;
        }

        return $contract->participants()
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->whereHas('user', function (Builder $query): void {
                $query->whereNotNull('id');
            })
            ->exists();
    }
}
