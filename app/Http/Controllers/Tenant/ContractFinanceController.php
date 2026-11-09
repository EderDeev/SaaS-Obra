<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Contract;
use App\Models\Tenant;
use App\Support\ContractFinanceReport;
use App\Support\ContractPermissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ContractFinanceController extends Controller
{
    private const AMOUNT_RULES = ['numeric', 'decimal:0,2', 'min:0', 'max:9999999999999.99'];

    public function show(Request $request, Tenant $tenant): Response
    {
        $section = $request->route()->defaults['section'];
        $dashboard = $section === 'Dashboard';
        $contractIds = ContractPermissions::contractIdsFor($request->user(), $tenant, ContractPermissions::VIEW);
        $contracts = $tenant->contracts()
            ->when($contractIds !== null, fn ($query) => $query->whereKey($contractIds))
            ->orderBy('code')
            ->select(['id', 'code', 'name', 'currency', 'contract_type', 'state', 'total_value', 'ends_at'])
            ->when(! $dashboard, fn ($query) => $query
                ->addSelect(['billed_total' => DB::table('contract_finance_months')
                    ->selectRaw('SUM(billed_amount)')->whereColumn('contract_id', 'contracts.id')])
                ->addSelect(['received_total' => DB::table('contract_finance_receipts')
                    ->selectRaw('SUM(received_amount)')->whereColumn('contract_id', 'contracts.id')]))
            ->get();

        $year = $request->filled('year') ? $request->integer('year') : ($dashboard ? null : now()->year);
        abort_if($year !== null && ($year < 1900 || $year > 9999), 422);
        $contract = $request->filled('contract_id')
            ? $contracts->firstWhere('id', $request->integer('contract_id'))
            : ($section === 'Parametrização' ? $contracts->first() : null);
        abort_if($request->filled('contract_id') && ! $contract, 404);

        $baseline = $contract ? DB::table('contract_finance_baselines')->where('contract_id', $contract->id)->first(['expected_amount', 'expected_date']) : null;
        $months = [];
        if ($contract && $section === 'Financeiro') {
            $receipts = DB::table('contract_finance_receipts')->where('contract_id', $contract->id)
                ->orderBy('received_date')->orderBy('id')->get()->groupBy('reference_month');
            $months = DB::table('contract_finance_months')->where('contract_id', $contract->id)
                ->orderByDesc('reference_month')
                ->get(['reference_month', 'revenue_amount', 'operating_cost', 'measured_amount', 'billed_amount', 'billed_date', 'revision'])
                ->map(fn ($month) => [
                    ...(array) $month,
                    'receipts' => $receipts->get($month->reference_month, collect()),
                ]);
        }
        $type = $request->string('contract_type')->toString();
        $state = $request->string('state')->toString();
        $reportContracts = $contracts
            ->when($contract, fn ($rows) => $rows->where('id', $contract->id))
            ->when($type !== '', fn ($rows) => $rows->where('contract_type', $type))
            ->when($state !== '', fn ($rows) => $rows->where('state', $state));
        $manageableContractIds = ! $dashboard && ContractPermissions::canAny($request->user(), $tenant, ContractPermissions::PARAMETRIZE)
            ? ContractPermissions::contractIdsFor($request->user(), $tenant, ContractPermissions::PARAMETRIZE)
            : [];

        return Inertia::render('Tenant/ContractFinance/Index', [
            'section' => $section,
            'contracts' => $contracts,
            'manageableContractIds' => $manageableContractIds,
            'selectedContract' => $contract,
            'canManage' => $contract && ContractPermissions::can($request->user(), $tenant, ContractPermissions::PARAMETRIZE, $contract),
            'baseline' => $baseline,
            'months' => $months,
            'year' => $year,
            'contractTypes' => Contract::TYPES,
            'filters' => ['contract_type' => $type, 'state' => $state],
            'target' => $contract && $section === 'Parametrização'
                ? DB::table('contract_finance_targets')->where('contract_id', $contract->id)->where('year', $year)->first() : null,
            'report' => $dashboard ? ContractFinanceReport::forContracts($reportContracts, $year) : [],
        ]);
    }

    public function saveTarget(Request $request, Tenant $tenant, Contract $contract): RedirectResponse
    {
        $data = $request->validate([
            'year' => ['required', 'integer', 'between:1900,9999'],
            'revenue_target' => ['nullable', ...self::AMOUNT_RULES],
            'margin_target' => ['nullable', 'numeric', 'decimal:0,2', 'between:-100,100'],
        ]);

        DB::table('contract_finance_targets')->upsert([
            'contract_id' => $contract->id,
            'year' => $data['year'],
            'revenue_target' => $data['revenue_target'] ?? null,
            'margin_target' => $data['margin_target'] ?? null,
        ], ['contract_id', 'year'], ['revenue_target', 'margin_target']);

        return to_route('tenant.contract-finance.settings', [$tenant, 'contract_id' => $contract->id, 'year' => $data['year']])
            ->with('success', 'Metas anuais salvas.');
    }

    public function saveBaseline(Request $request, Tenant $tenant, Contract $contract): RedirectResponse
    {
        $data = $request->validate([
            'expected_amount' => ['required', ...self::AMOUNT_RULES, 'gt:0'],
            'expected_date' => ['required', 'date_format:Y-m-d'],
        ]);

        DB::table('contract_finance_baselines')->upsert([
            'contract_id' => $contract->id,
            ...$data,
            'created_at' => now(),
            'updated_at' => now(),
        ], ['contract_id'], ['expected_amount', 'expected_date', 'updated_at']);

        return to_route('tenant.contract-finance.settings', [$tenant, 'contract_id' => $contract->id, 'year' => $request->integer('year') ?: null])
            ->with('success', 'Linha de base salva.');
    }

    public function saveMonth(Request $request, Tenant $tenant, Contract $contract): RedirectResponse
    {
        $data = $request->validate([
            'reference_month' => ['required', 'date_format:Y-m'],
            'revenue_amount' => ['nullable', ...self::AMOUNT_RULES],
            'operating_cost' => ['nullable', ...self::AMOUNT_RULES],
            'measured_amount' => ['nullable', ...self::AMOUNT_RULES],
            'billed_amount' => ['nullable', 'required_with:billed_date', ...self::AMOUNT_RULES],
            'billed_date' => ['nullable', 'required_with:billed_amount', 'date_format:Y-m-d'],
            'receipts' => ['present', 'array', 'max:200'],
            'receipts.*' => ['required', 'array:received_amount,received_date'],
            'receipts.*.received_amount' => ['required', ...self::AMOUNT_RULES, 'gt:0'],
            'receipts.*.received_date' => ['required', 'date_format:Y-m-d'],
            'revision' => ['required', 'integer', 'min:0'],
        ]);

        $scope = ['contract_id' => $contract->id, 'reference_month' => $data['reference_month'].'-01'];
        $receipts = $data['receipts'];
        $amounts = array_intersect_key($data, array_flip(['revenue_amount', 'operating_cost', 'measured_amount', 'billed_amount', 'billed_date']));

        DB::transaction(function () use ($contract, $data, $scope, $amounts, $receipts): void {
            Contract::query()->whereKey($contract->id)->lockForUpdate()->firstOrFail();
            $month = DB::table('contract_finance_months')->where($scope)->first();
            $revision = (int) ($month->revision ?? 0);
            if ((int) ($data['revision'] ?? 0) !== $revision) {
                throw ValidationException::withMessages(['revision' => 'Este lançamento foi atualizado. Recarregue a página antes de editar.']);
            }
            if (! $receipts && ! array_filter($amounts, fn ($value) => $value !== null)) {
                throw ValidationException::withMessages(['reference_month' => 'Informe pelo menos um valor para salvar a competência.']);
            }

            DB::table('contract_finance_months')->upsert([
                ...$scope, ...$amounts, 'revision' => $revision + 1, 'created_at' => now(), 'updated_at' => now(),
            ], array_keys($scope), [...array_keys($amounts), 'revision', 'updated_at']);
            DB::table('contract_finance_receipts')->where($scope)->delete();
            if ($receipts) {
                DB::table('contract_finance_receipts')->insert(array_map(fn ($receipt) => [...$scope, ...$receipt], $receipts));
            }
        });

        return to_route('tenant.contract-finance.index', [$tenant, 'contract_id' => $contract->id])
            ->with('success', 'Lançamento mensal salvo.');
    }
}
