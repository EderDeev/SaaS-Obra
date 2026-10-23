<?php

namespace App\Http\Controllers\Tenant\Qualidade;

use App\Http\Controllers\Controller;
use App\Models\Contract;
use App\Models\RelatorioNaoConformidadeResponsavel;
use App\Models\Tenant;
use App\Models\User;
use App\Models\RncSignatureSetting;
use App\Services\RncSignatureService;
use App\Support\RncPermissions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class RncResponsavelController extends Controller
{
    public function index(Request $request, Tenant $tenant): Response
    {
        abort_unless(RncPermissions::canAny($request->user(), $tenant, RncPermissions::RESPONSIBLES), 403);

        $contractIds = RncPermissions::contractIdsFor($request->user(), $tenant, RncPermissions::RESPONSIBLES);
        $contracts = $tenant->contracts()
            ->when($contractIds !== null, fn ($query) => $query->whereIn('id', $contractIds))
            ->with('obra:id,nome')
            ->orderBy('code')
            ->get();

        return Inertia::render('Tenant/Qualidade/RelatorioNaoConformidade/Responsaveis', [
            'tenant' => $tenant,
            'contracts' => $contracts->map(fn (Contract $contract): array => [
                'id' => $contract->id,
                'code' => $contract->code,
                'name' => $contract->obra?->nome ?? $contract->name,
                'status' => $contract->status,
            ])->values(),
            'usersByContract' => $this->responsibleCandidateUsersByContract($tenant, $contracts),
            'signatureRoles' => RncSignatureSetting::ROLES,
            'signatureSettings' => RncSignatureSetting::where('tenant_id', $tenant->id)->whereIn('contract_id', $contracts->pluck('id'))->get()->mapWithKeys(fn ($setting) => [$setting->contract_id => $setting->signers ?? []]),
            'responsibilityProfiles' => collect(RncPermissions::responsibilityProfiles())
                ->map(fn (array $profile, string $value): array => [
                    'value' => $value,
                    'label' => $profile['label'],
                    'description' => $profile['description'],
                    'permissions' => collect($profile['permissions'])
                        ->map(fn (string $permission): string => RncPermissions::labels()[$permission])
                        ->values(),
                ])
                ->values(),
            'responsaveis' => $tenant->relatorioNaoConformidadeResponsaveis()
                ->where('status', 'active')
                ->with(['user:id,name,email,avatar_url', 'contract:id,code,name,obra_id', 'contract.obra:id,nome'])
                ->latest()
                ->get()
                ->map(fn (RelatorioNaoConformidadeResponsavel $responsavel): array => [
                    'id' => $responsavel->id,
                    'user' => $responsavel->user,
                    'contract' => [
                        'id' => $responsavel->contract?->id,
                        'code' => $responsavel->contract?->code,
                        'name' => $responsavel->contract?->obra?->nome ?? $responsavel->contract?->name,
                    ],
                    'responsibility_type' => $responsavel->responsibility_type,
                    'responsibility_label' => RncPermissions::responsibilityProfiles()[$responsavel->responsibility_type]['label']
                        ?? RncPermissions::responsibilityProfiles()[RncPermissions::RESPONSIBILITY_MONITORING]['label'],
                    'created_at' => $responsavel->created_at?->format('d/m/Y H:i'),
                ])
                ->values(),
        ]);
    }

    public function store(Request $request, Tenant $tenant): RedirectResponse
    {
        abort_unless(RncPermissions::canAny($request->user(), $tenant, RncPermissions::RESPONSIBLES), 403);

        $data = $request->validate([
            'contract_id' => [
                'required',
                Rule::exists('contracts', 'id')->where(fn ($query) => $query->where('tenant_id', $tenant->id)),
            ],
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'responsibility_type' => ['required', Rule::in(RncPermissions::responsibilityTypes())],
        ], [
            'contract_id.required' => 'Selecione o contrato.',
            'contract_id.exists' => 'O contrato selecionado nao pertence a este tenant.',
            'user_id.required' => 'Selecione o usuario.',
            'user_id.exists' => 'O usuario selecionado nao existe.',
            'responsibility_type.required' => 'Selecione o tipo de responsável.',
            'responsibility_type.in' => 'Selecione um tipo de responsável válido.',
        ]);

        $contract = $tenant->contracts()->findOrFail($data['contract_id']);
        $user = User::findOrFail($data['user_id']);

        abort_unless(RncPermissions::can($request->user(), $tenant, RncPermissions::RESPONSIBLES, $contract), 403);

        if (! $this->canAccessContract($user, $tenant, $contract)) {
            throw ValidationException::withMessages([
                'user_id' => 'Selecione um usuario com acesso ativo a este contrato.',
            ]);
        }

        $responsavel = RelatorioNaoConformidadeResponsavel::withTrashed()
            ->where('contract_id', $contract->id)
            ->where('user_id', $user->id)
            ->firstOrNew([
                'contract_id' => $contract->id,
                'user_id' => $user->id,
            ]);

        $responsavel->fill([
            'tenant_id' => $tenant->id,
            'created_by_id' => $request->user()->id,
            'status' => 'active',
            'responsibility_type' => $data['responsibility_type'],
            'permissions' => RncPermissions::mergeResponsibilityPermissions(
                $data['responsibility_type'],
                $responsavel->permissions ?: [],
            ),
        ]);
        $responsavel->save();

        if ($responsavel->trashed()) {
            $responsavel->restore();
        }

        return back()->with('success', 'Responsável de RNC salvo com sucesso.');
    }

    public function destroy(Request $request, Tenant $tenant, RelatorioNaoConformidadeResponsavel $responsavel): RedirectResponse
    {
        abort_unless(RncPermissions::canAny($request->user(), $tenant, RncPermissions::RESPONSIBLES), 403);
        abort_unless((int) $responsavel->tenant_id === (int) $tenant->id, 404);
        abort_unless(RncPermissions::can($request->user(), $tenant, RncPermissions::RESPONSIBLES, $responsavel->contract), 403);

        $responsavel->update(['status' => 'inactive']);
        $responsavel->delete();

        return back()->with('success', 'Responsável removido com sucesso.');
    }

    public function storeSignatures(Request $request, Tenant $tenant): RedirectResponse
    {
        $data = $request->validate(['contract_id' => ['required', 'integer'], 'signers' => ['present', 'array:cliente,gerenciadora,construtora'],
            'signers.*' => ['nullable', 'integer']]);
        $contract = $tenant->contracts()->findOrFail($data['contract_id']);
        abort_unless(RncPermissions::can($request->user(), $tenant, RncPermissions::RESPONSIBLES, $contract), 403);
        $selected = [];
        $emails = [];
        foreach (RncSignatureSetting::ROLES as $role => $label) {
            $id = $data['signers'][$role] ?? null;
            if (!$id) continue;
            $user = User::find($id);
            if (!$user || !RncSignatureService::eligibleUser($tenant, $contract->id, $user)) throw ValidationException::withMessages(["signers.{$role}" => 'Selecione um usuário com vínculo ativo neste contrato.']);
            if (in_array(mb_strtolower($user->email), $emails, true)) throw ValidationException::withMessages(["signers.{$role}" => 'Escolha responsáveis com e-mails diferentes para cada parte.']);
            $emails[] = mb_strtolower($user->email);
            $selected[$role] = $user->id;
        }
        RncSignatureSetting::updateOrCreate(['tenant_id' => $tenant->id, 'contract_id' => $contract->id], ['signers' => $selected, 'updated_by_id' => $request->user()->id]);
        return back()->with('success', 'Responsáveis pelas assinaturas da RNC salvos.');
    }

    /**
     * @param  Collection<int, Contract>  $contracts
     * @return array<int, array<int, array<string, mixed>>>
     */
    private function responsibleCandidateUsersByContract(Tenant $tenant, Collection $contracts): array
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
