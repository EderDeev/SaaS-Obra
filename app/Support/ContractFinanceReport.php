<?php

namespace App\Support;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ContractFinanceReport
{
    public static function forContracts(Collection $contracts, ?int $year): array
    {
        $ids = $contracts->pluck('id');
        $months = DB::table('contract_finance_months')->whereIn('contract_id', $ids)
            ->orderBy('reference_month')->get()->groupBy('contract_id');
        $receipts = DB::table('contract_finance_receipts')->whereIn('contract_id', $ids)
            ->get()->groupBy('contract_id');
        $baselines = DB::table('contract_finance_baselines')->whereIn('contract_id', $ids)
            ->get()->keyBy('contract_id');
        $targets = $year ? DB::table('contract_finance_targets')->whereIn('contract_id', $ids)
            ->where('year', $year)->get()->keyBy('contract_id') : collect();

        $rows = $contracts->map(function ($contract) use ($months, $receipts, $baselines, $targets, $year) {
            $contractMonths = $months->get($contract->id, collect());
            $contractReceipts = $receipts->get($contract->id, collect());
            $baseline = $baselines->get($contract->id);
            $target = $targets->get($contract->id);
            $competence = self::inYear($contractMonths, 'reference_month', $year);
            $billing = self::inYear($contractMonths, 'billed_date', $year);
            $cash = self::inYear($contractReceipts, 'received_date', $year);
            $revenue = self::sum($competence, 'revenue_amount');
            $cost = self::sum($competence, 'operating_cost');
            $comparable = $competence->where(fn ($row) => $row->revenue_amount !== null || $row->operating_cost !== null);
            $complete = $comparable->isNotEmpty() && $comparable->every(fn ($row) => $row->revenue_amount !== null && $row->operating_cost !== null);
            $measured = self::sum($contractMonths, 'measured_amount');
            $billed = self::sum($billing, 'billed_amount') ?? 0;
            $received = self::sum($cash, 'received_amount') ?? 0;
            $receivedToDate = self::sum($contractReceipts, 'received_amount') ?? 0;

            return [
                'contract' => $contract,
                'baseline' => $baseline,
                'target' => $target,
                'revenue' => $revenue,
                'cost' => $cost,
                'result' => $complete ? round($revenue - $cost, 2) : null,
                'margin' => $complete && $revenue > 0 ? 100 * ($revenue - $cost) / $revenue : null,
                'billed' => $billed,
                'received' => $received,
                'outstanding' => round((self::sum($contractMonths, 'billed_amount') ?? 0) - $receivedToDate, 2),
                'baseline_balance' => $baseline ? round($baseline->expected_amount - $receivedToDate, 2) : null,
                'measured_percent' => $measured !== null && $contract->total_value > 0 ? 100 * $measured / $contract->total_value : null,
                'series' => self::series($competence, $billing, $cash),
            ];
        });

        return $rows->groupBy(fn ($row) => $row['contract']->currency ?: 'BRL')
            ->map(function ($rows, $currency) {
                $revenue = $rows->sum('revenue');
                $operations = $rows->filter(fn ($row) => $row['revenue'] !== null || $row['cost'] !== null);
                $result = $operations->every(fn ($row) => $row['result'] !== null) ? self::sum($operations, 'result') : null;

                return [
                    'currency' => $currency,
                    'revenue' => self::sum($rows, 'revenue'),
                    'cost' => self::sum($rows, 'cost'),
                    'result' => $result,
                    'margin' => $result !== null && $revenue > 0 ? 100 * $result / $revenue : null,
                    'billed' => self::sum($rows, 'billed'),
                    'received' => self::sum($rows, 'received'),
                    'types' => $rows->groupBy(fn ($row) => $row['contract']->contract_type ?? 'unclassified')
                        ->map(fn ($typeRows, $type) => ['type' => $type, 'revenue' => self::sum($typeRows, 'revenue')])->values(),
                    'contracts' => $rows->map(fn ($row) => [
                        ...$row,
                        'participation' => $revenue > 0 && $row['revenue'] !== null ? 100 * $row['revenue'] / $revenue : null,
                    ])->values(),
                ];
            })->values()->all();
    }

    private static function inYear(Collection $rows, string $date, ?int $year): Collection
    {
        return $rows->filter(fn ($row) => $row->{$date} && (! $year || substr($row->{$date}, 0, 4) === (string) $year));
    }

    private static function sum(Collection $rows, string $field): ?float
    {
        $values = $rows->pluck($field)->filter(fn ($value) => $value !== null);

        return $values->isEmpty() ? null : $values->sum(fn ($value) => (int) round((float) $value * 100)) / 100;
    }

    private static function series(Collection $competence, Collection $billing, Collection $cash): array
    {
        $group = fn ($rows, $date) => $rows->groupBy(fn ($row) => substr($row->{$date}, 0, 7));
        $operations = $group($competence, 'reference_month');
        $invoices = $group($billing, 'billed_date');
        $receipts = $group($cash, 'received_date');
        $accumulated = 0;

        return $operations->keys()->merge($invoices->keys())->merge($receipts->keys())->unique()->sort()->values()
            ->map(function ($month) use ($operations, $invoices, $receipts, &$accumulated) {
                $revenue = self::sum($operations->get($month, collect()), 'revenue_amount');
                $accumulated = round($accumulated + ($revenue ?? 0), 2);

                return [
                    'month' => $month,
                    'revenue' => $revenue,
                    'accumulated' => $accumulated,
                    'cost' => self::sum($operations->get($month, collect()), 'operating_cost'),
                    'billed' => self::sum($invoices->get($month, collect()), 'billed_amount'),
                    'received' => self::sum($receipts->get($month, collect()), 'received_amount'),
                ];
            })->all();
    }
}
