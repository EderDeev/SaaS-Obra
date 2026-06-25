<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Contract;
use App\Models\Obra;
use App\Models\RdoResponsavel;
use App\Models\Tenant;
use App\Models\TenantUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class RdoResponsavelController extends Controller
{
    private const STAGES = [
        'construtora' => 'Preenchimento da construtora',
        'gerenciadora' => 'Aprovação da gerenciadora',
        'cliente' => 'Aprovação do cliente',
        'assinatura' => 'Assinatura do RDO',
    ];

    public function index(Request $request, Tenant $tenant): Response
    {
        $contracts = $tenant->contracts()
            ->with(['construtoraEmpresa:id,nome', 'gerenciadoraEmpresa:id,nome', 'clienteEmpresa:id,nome'])
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'construtora_empresa_id', 'fiscalizadora_empresa_id', 'cliente_empresa_id']);
        $selectedContractId = $request->integer('contract_id') ?: (int) ($contracts->first()?->id ?? 0);
        $contract = $contracts->firstWhere('id', $selectedContractId);

        $obras = Obra::query()
            ->where('tenant_id', $tenant->id)
            ->where('contract_id', $selectedContractId)
            ->orderBy('codigo')
            ->orderBy('nome')
            ->get(['id', 'codigo', 'nome']);

        $users = TenantUser::query()
            ->where('tenant_id', $tenant->id)
            ->where('status', 'active')
            ->with(['user:id,name,email,avatar_url', 'empresa:id,nome'])
            ->get()
            ->filter(fn (TenantUser $membership) => $membership->user !== null)
            ->map(fn (TenantUser $membership) => [
                'id' => $membership->user_id,
                'name' => $membership->user->name,
                'email' => $membership->user->email,
                'avatar_url' => $membership->user->avatar_url,
                'empresa_id' => $membership->empresa_id,
                'empresa' => $membership->empresa?->nome,
            ])
            ->sortBy('name')
            ->values();

        $responsaveis = RdoResponsavel::query()
            ->where('tenant_id', $tenant->id)
            ->when($selectedContractId, fn ($query) => $query->where('contract_id', $selectedContractId))
            ->with(['obra:id,codigo,nome', 'user:id,name,email,avatar_url'])
            ->orderBy('obra_id')
            ->orderBy('etapa')
            ->get()
            ->filter(fn (RdoResponsavel $responsavel) => $responsavel->user !== null)
            ->groupBy('user_id')
            ->map(function ($userResponsibilities) {
                $first = $userResponsibilities->first();

                return [
                    'user' => $first->user,
                    'vinculos' => $userResponsibilities
                        ->map(fn (RdoResponsavel $responsavel) => [
                            'id' => $responsavel->id,
                            'etapa' => $responsavel->etapa,
                            'etapa_label' => self::STAGES[$responsavel->etapa],
                            'obra' => $responsavel->obra,
                        ])
                        ->sortBy(fn (array $link) => ($link['etapa_label'] ?? '').($link['obra']?->codigo ?? ''))
                        ->values(),
                ];
            })
            ->sortBy(fn (array $responsavel) => $responsavel['user']->name)
            ->values();

        return Inertia::render('Tenant/Rdo/Responsaveis', [
            'contracts' => $contracts,
            'selectedContractId' => $selectedContractId,
            'contractCompanies' => $contract ? [
                'construtora' => $contract->construtoraEmpresa,
                'gerenciadora' => $contract->gerenciadoraEmpresa,
                'cliente' => $contract->clienteEmpresa,
            ] : null,
            'obras' => $obras,
            'users' => $users,
            'stages' => collect(self::STAGES)->map(fn ($label, $value) => compact('value', 'label'))->values(),
            'responsaveis' => $responsaveis,
        ]);
    }

    public function store(Request $request, Tenant $tenant): RedirectResponse
    {
        $validated = $request->validate([
            'contract_id' => ['required', Rule::exists('contracts', 'id')->where('tenant_id', $tenant->id)],
            'obra_id' => ['required', Rule::exists('obras', 'id')->where('tenant_id', $tenant->id)],
            'user_id' => ['required', Rule::exists('users', 'id')],
            'etapa' => ['required', Rule::in(array_keys(self::STAGES))],
        ]);

        $contract = Contract::query()->where('tenant_id', $tenant->id)->findOrFail($validated['contract_id']);
        abort_unless(
            Obra::query()->where('tenant_id', $tenant->id)->where('contract_id', $contract->id)->whereKey($validated['obra_id'])->exists(),
            422,
            'A frente de serviço selecionada não pertence ao contrato.'
        );

        $expectedCompanyId = match ($validated['etapa']) {
            'construtora' => $contract->construtora_empresa_id,
            'gerenciadora' => $contract->fiscalizadora_empresa_id,
            'cliente' => $contract->cliente_empresa_id,
            'assinatura' => null,
        };
        abort_if($validated['etapa'] !== 'assinatura' && ! $expectedCompanyId, 422, 'Vincule a empresa desta etapa ao contrato antes de cadastrar o responsável.');
        abort_unless(
            TenantUser::query()
                ->where('tenant_id', $tenant->id)
                ->where('user_id', $validated['user_id'])
                ->when($expectedCompanyId, fn ($query) => $query->where('empresa_id', $expectedCompanyId))
                ->where('status', 'active')
                ->exists(),
            422,
            $validated['etapa'] === 'assinatura'
                ? 'O usuário selecionado não possui vínculo ativo neste tenant.'
                : 'O usuário selecionado não pertence à empresa responsável por esta etapa.'
        );

        $responsavel = RdoResponsavel::withTrashed()->firstOrNew([
            'tenant_id' => $tenant->id,
            'obra_id' => $validated['obra_id'],
            'user_id' => $validated['user_id'],
            'etapa' => $validated['etapa'],
        ]);
        $responsavel->fill([
            'contract_id' => $contract->id,
            'created_by_id' => $request->user()?->id,
            'status' => 'active',
        ]);
        if ($responsavel->trashed()) {
            $responsavel->restore();
        }
        $responsavel->save();

        return back()->with('success', 'Responsável do RDO cadastrado com sucesso.');
    }

    public function destroy(Tenant $tenant, RdoResponsavel $responsavel): RedirectResponse
    {
        abort_unless((int) $responsavel->tenant_id === (int) $tenant->id, 404);
        $responsavel->update(['status' => 'inactive']);
        $responsavel->delete();

        return back()->with('success', 'Responsável removido do RDO.');
    }
}
