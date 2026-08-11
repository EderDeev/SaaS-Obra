<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Contract;
use App\Models\OrdemServico;
use App\Models\OrdemServicoItem;
use App\Models\Tenant;
use App\Support\OrdemServicoPermissions;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class OrdemServicoMetricsController extends Controller
{
    private const STATUS_LABELS = [
        'rascunho' => 'Rascunho',
        'em_analise' => 'Em análise',
        'em_aprovacao' => 'Em aprovação',
        'aprovada' => 'Aprovada',
        'em_execucao' => 'Em execução',
        'concluida' => 'Concluída',
        'cancelada' => 'Cancelada',
        'recusada' => 'Recusada',
    ];

    private const ACTIVE_STATUSES = [
        'rascunho',
        'em_analise',
        'em_aprovacao',
        'aprovada',
        'em_execucao',
    ];

    public function __invoke(Request $request, Tenant $tenant): Response
    {
        abort_unless(
            OrdemServicoPermissions::canAny($request->user(), $tenant, OrdemServicoPermissions::METRICS),
            403
        );

        $filters = $request->validate([
            'period' => ['nullable', Rule::in(['30', '90', '180', '365', 'all'])],
            'contract_id' => ['nullable', 'integer'],
        ]);
        $period = $filters['period'] ?? '180';
        $permissionContractIds = OrdemServicoPermissions::contractIdsFor(
            $request->user(),
            $tenant,
            OrdemServicoPermissions::METRICS
        );
        $contractQuery = $tenant->contracts()
            ->with('obra:id,nome')
            ->orderBy('code');

        if ($permissionContractIds !== null) {
            $contractQuery->whereIn('contracts.id', $permissionContractIds);
        }

        $contracts = $contractQuery->get(['id', 'code', 'name', 'obra_id']);

        $contractIds = $contracts->pluck('id')->map(fn ($id): int => (int) $id);
        $contractId = ! empty($filters['contract_id']) ? (int) $filters['contract_id'] : null;

        if ($contractId !== null) {
            abort_unless($contractIds->contains($contractId), 403);
        }

        $orders = OrdemServico::query()
            ->where('tenant_id', $tenant->id)
            ->whereIn('contract_id', $contractIds)
            ->when($contractId, fn ($query) => $query->where('contract_id', $contractId))
            ->when($period !== 'all', fn ($query) => $query->where(
                'created_at',
                '>=',
                CarbonImmutable::now()->subDays((int) $period)->startOfDay()
            ))
            ->with([
                'contract:id,code,name',
                'obra:id,codigo,nome',
                'itens' => fn ($query) => $this->withMeasuredQuantity($query),
                'itens.medicaoItem:id,item,codigo,descricao,unidade,quantidade_prevista,valor_unitario,valor_com_bdi,valor_total',
            ])
            ->get();

        $today = CarbonImmutable::today();
        $actualCosts = $orders->mapWithKeys(fn (OrdemServico $order): array => [
            $order->id => $this->actualCost($order),
        ]);
        $active = $orders->whereIn('status', self::ACTIVE_STATUSES);
        $completed = $orders->where('status', 'concluida');
        $awaitingDecision = $orders->whereIn('status', ['em_analise', 'em_aprovacao']);
        $overdueOpen = $active->filter(fn (OrdemServico $order): bool => $this->isOverdue($order, $today));
        $dueSoon = $active->filter(fn (OrdemServico $order): bool => $this->isDueSoon($order, $today));
        $onTrack = $active->filter(fn (OrdemServico $order): bool => $order->prazo_finalizacao
            && $order->prazo_finalizacao->gt($today->addDays(30)));
        $activeWithoutDeadline = $active->whereNull('prazo_finalizacao');
        $completedWithDeadline = $completed->whereNotNull('prazo_finalizacao');
        $completedOnTime = $completedWithDeadline->filter(fn (OrdemServico $order): bool => $this->completedOnTime($order));
        $completedLate = $completedWithDeadline->reject(fn (OrdemServico $order): bool => $this->completedOnTime($order));
        $plannedCost = round((float) $orders->sum(
            fn (OrdemServico $order): float => $this->plannedCost($order)
        ), 2);
        $actualCost = round((float) $actualCosts->sum(), 2);

        return Inertia::render('Tenant/OrdemServico/Metrics', [
            'tenant' => $tenant,
            'filters' => [
                'period' => $period,
                'contract_id' => $contractId,
            ],
            'filterOptions' => [
                'contracts' => $contracts->map(fn (Contract $contract): array => [
                    'id' => $contract->id,
                    'code' => $contract->code,
                    'name' => $contract->obra?->nome ?? $contract->name,
                ])->values(),
            ],
            'summary' => [
                'total' => $orders->count(),
                'active' => $active->count(),
                'awaiting_decision' => $awaitingDecision->count(),
                'in_execution' => $orders->where('status', 'em_execucao')->count(),
                'completed' => $completed->count(),
                'completion_rate' => $orders->isNotEmpty()
                    ? round(($completed->count() / $orders->count()) * 100, 1)
                    : 0,
                'overdue' => $overdueOpen->count(),
                'planned_cost' => $plannedCost,
                'actual_cost' => $actualCost,
                'financial_progress' => $plannedCost > 0 ? round(($actualCost / $plannedCost) * 100, 1) : 0,
            ],
            'averageTimes' => [
                'analysis_days' => $this->averageElapsedDays($orders, 'submitted_for_review_at', 'analyzed_at'),
                'approval_days' => $this->averageElapsedDays($orders, 'analyzed_at', 'approval_decided_at'),
                'execution_days' => $this->averageElapsedDays($orders, 'execution_started_at', 'completed_at'),
            ],
            'charts' => [
                'statuses' => collect(self::STATUS_LABELS)
                    ->map(fn (string $label, string $status): array => [
                        'key' => $status,
                        'name' => $label,
                        'value' => $orders->where('status', $status)->count(),
                    ])
                    ->values(),
                'deadlines' => [
                    ['key' => 'overdue', 'name' => 'Abertas vencidas', 'value' => $overdueOpen->count()],
                    ['key' => 'due_soon', 'name' => 'Vencem em até 30 dias', 'value' => $dueSoon->count()],
                    ['key' => 'on_track', 'name' => 'Prazo acima de 30 dias', 'value' => $onTrack->count()],
                    ['key' => 'completed_on_time', 'name' => 'Concluídas no prazo', 'value' => $completedOnTime->count()],
                    ['key' => 'completed_late', 'name' => 'Concluídas com atraso', 'value' => $completedLate->count()],
                    ['key' => 'without_deadline', 'name' => 'Abertas sem prazo', 'value' => $activeWithoutDeadline->count()],
                ],
                'trend' => $this->trend($orders, $period),
                'financial_by_contract' => $this->financialByContract($orders, $actualCosts),
            ],
            'attention' => [
                'overdue' => $overdueOpen
                    ->sortBy('prazo_finalizacao')
                    ->take(8)
                    ->map(fn (OrdemServico $order): array => $this->attentionOrder($tenant, $order, [
                        'days' => $order->prazo_finalizacao->diffInDays($today),
                        'deadline' => $order->prazo_finalizacao->format('d/m/Y'),
                    ]))
                    ->values(),
                'review' => $awaitingDecision
                    ->sortBy(fn (OrdemServico $order) => $order->submitted_for_review_at ?? $order->created_at)
                    ->take(8)
                    ->map(fn (OrdemServico $order): array => $this->attentionOrder($tenant, $order, [
                        'days' => ($order->submitted_for_review_at ?? $order->created_at)
                            ->copy()
                            ->startOfDay()
                            ->diffInDays(now()->startOfDay()),
                        'stage' => self::STATUS_LABELS[$order->status] ?? $order->status,
                    ]))
                    ->values(),
            ],
        ]);
    }

    private function withMeasuredQuantity($query)
    {
        return $query->withSum([
            'folhaRostoItemAnalises as quantidade_medida' => fn ($analysisQuery) => $analysisQuery
                ->where('setor', 'medicao')
                ->whereHas(
                    'folhaRostoItem.folhaRosto',
                    fn ($folhaQuery) => $folhaQuery->where('status', 'analisada')
                ),
        ], 'quantidade_aprovada');
    }

    private function actualCost(OrdemServico $order): float
    {
        return round((float) $order->itens->sum(function (OrdemServicoItem $item): float {
            $requested = (float) $item->quantidade_solicitada;
            $unitValue = $requested > 0
                ? (float) $item->valor_previsto / $requested
                : 0;

            if ($unitValue <= 0 && $item->medicaoItem) {
                $unitValue = (float) ($item->medicaoItem->valor_com_bdi ?: $item->medicaoItem->valor_unitario);
                $contractQuantity = (float) $item->medicaoItem->quantidade_prevista;

                if ($unitValue <= 0 && $contractQuantity > 0) {
                    $unitValue = (float) $item->medicaoItem->valor_total / $contractQuantity;
                }
            }

            return (float) ($item->quantidade_medida ?? 0) * $unitValue;
        }), 2);
    }

    private function plannedCost(OrdemServico $order): float
    {
        return round((float) $order->itens->sum('valor_previsto'), 2);
    }

    private function isOverdue(OrdemServico $order, CarbonImmutable $today): bool
    {
        return $order->prazo_finalizacao !== null && $order->prazo_finalizacao->lt($today);
    }

    private function isDueSoon(OrdemServico $order, CarbonImmutable $today): bool
    {
        return $order->prazo_finalizacao !== null
            && $order->prazo_finalizacao->gte($today)
            && $order->prazo_finalizacao->lte($today->addDays(30));
    }

    private function completedOnTime(OrdemServico $order): bool
    {
        return $order->completed_at !== null
            && $order->prazo_finalizacao !== null
            && $order->completed_at->lte($order->prazo_finalizacao->copy()->endOfDay());
    }

    private function averageElapsedDays(Collection $orders, string $start, string $end): float
    {
        $durations = $orders
            ->filter(fn (OrdemServico $order): bool => $order->{$start} !== null && $order->{$end} !== null)
            ->map(fn (OrdemServico $order): float => max(
                0,
                $order->{$start}->diffInMinutes($order->{$end}) / 1440
            ));

        return $durations->isEmpty() ? 0 : round((float) $durations->average(), 1);
    }

    private function trend(Collection $orders, string $period): Collection
    {
        $months = match ($period) {
            '30' => 2,
            '90' => 4,
            '180' => 7,
            default => 12,
        };
        $currentMonth = CarbonImmutable::now()->startOfMonth();

        return collect(range($months - 1, 0))
            ->map(function (int $offset) use ($orders, $currentMonth): array {
                $start = $currentMonth->subMonths($offset);
                $end = $start->endOfMonth();

                return [
                    'key' => $start->format('Y-m'),
                    'label' => ucfirst($start->translatedFormat('M/y')),
                    'created' => $orders->filter(fn (OrdemServico $order): bool => $order->created_at->between($start, $end))->count(),
                    'completed' => $orders->filter(fn (OrdemServico $order): bool => $order->completed_at?->between($start, $end) ?? false)->count(),
                ];
            });
    }

    private function financialByContract(Collection $orders, Collection $actualCosts): Collection
    {
        return $orders
            ->groupBy('contract_id')
            ->map(function (Collection $contractOrders) use ($actualCosts): array {
                $contract = $contractOrders->first()?->contract;
                $planned = round((float) $contractOrders->sum(
                    fn (OrdemServico $order): float => $this->plannedCost($order)
                ), 2);
                $actual = round((float) $contractOrders->sum(
                    fn (OrdemServico $order): float => (float) ($actualCosts[$order->id] ?? 0)
                ), 2);

                return [
                    'id' => $contract?->id,
                    'label' => $contract?->code ?? 'Sem contrato',
                    'name' => $contract?->name ?? 'Sem contrato',
                    'planned' => $planned,
                    'actual' => $actual,
                    'progress' => $planned > 0 ? round(($actual / $planned) * 100, 1) : 0,
                ];
            })
            ->sortByDesc('planned')
            ->take(8)
            ->values();
    }

    /** @param array<string, mixed> $extra */
    private function attentionOrder(Tenant $tenant, OrdemServico $order, array $extra): array
    {
        return [
            'id' => $order->id,
            'code' => $order->codigo,
            'title' => $order->titulo,
            'contract' => $order->contract?->code,
            'obra' => $order->obra?->nome,
            'url' => route('tenant.ordem-servico.os.show', [$tenant, $order]),
            ...$extra,
        ];
    }
}
