<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\OsDnitHighway;
use App\Models\OsDnitOrder;
use App\Models\OsDnitOrderEvent;
use App\Models\OsDnitSupervisionLot;
use App\Models\Tenant;
use App\Support\OsDnitApprovedDeadline;
use App\Support\OsDnitContractAccess;
use App\Support\OsDnitPermissions;
use App\Support\OsDnitWorkflowAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class OsDnitDashboardController extends Controller
{
    public function __invoke(Request $request, Tenant $tenant): Response
    {
        $contracts = OsDnitContractAccess::configuredContracts($request->user(), $tenant, OsDnitPermissions::DASHBOARD)->orderBy('code')->get(['id', 'code', 'name']);
        $request->merge([
            'date_from' => $request->input('date_from') ?? now()->startOfMonth()->subMonths(11)->toDateString(),
            'date_to' => $request->input('date_to') ?? now()->toDateString(),
        ]);
        $filters = $request->validate([
            'contract_id' => ['nullable', 'integer', Rule::in($contracts->pluck('id')->all())],
            'status' => ['nullable', Rule::in(array_keys(OsDnitOrder::STATUS_FILTERS))],
            'date_from' => ['required', 'date_format:Y-m-d'],
            'date_to' => ['required', 'date_format:Y-m-d', 'after_or_equal:date_from'],
        ], ['date_to.after_or_equal' => 'A data final deve ser igual ou posterior à data inicial.'],
            ['date_from' => 'data inicial', 'date_to' => 'data final', 'contract_id' => 'contrato']);
        $from = Carbon::parse($filters['date_from'])->startOfDay();
        $to = Carbon::parse($filters['date_to'])->endOfDay();
        if ($from->copy()->startOfMonth()->diffInMonths($to->copy()->startOfMonth()) >= 24) {
            throw ValidationException::withMessages(['date_from' => 'Selecione um período de até 24 meses.']);
        }

        $highways = OsDnitHighway::where('tenant_id', $tenant->id)->whereIn('contract_id', $contracts->pluck('id'))
            ->when($filters['contract_id'] ?? null, fn ($query, $id) => $query->where('contract_id', $id))
            ->with('contract:id,code')->orderBy('name')->get(['id', 'contract_id', 'name']);
        $filters += $request->validate(['highway_id' => ['nullable', 'integer', Rule::in($highways->pluck('id')->all())]],
            [], ['highway_id' => 'rodovia']);

        $lots = OsDnitSupervisionLot::where('tenant_id', $tenant->id)->whereIn('contract_id', $contracts->pluck('id'))
            ->when($filters['contract_id'] ?? null, fn ($query, $id) => $query->where('contract_id', $id))
            ->with('contract:id,code')->orderBy('name')->get(['id', 'contract_id', 'name']);
        $filters += $request->validate(['supervision_lot_id' => ['nullable', 'integer', Rule::in($lots->pluck('id')->all())]],
            [], ['supervision_lot_id' => 'lote de supervisão']);

        $accessible = OsDnitOrder::where('tenant_id', $tenant->id)->whereIn('contract_id', $contracts->pluck('id'))
            ->when($filters['contract_id'] ?? null, fn ($query, $id) => $query->where('contract_id', $id))
            ->when($filters['supervision_lot_id'] ?? null, fn ($query, $id) => $query->where('supervision_lot_id', $id))
            ->when($filters['highway_id'] ?? null, fn ($query, $id) => $query->where('highway_id', $id))
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->whereIn('status', OsDnitOrder::STATUS_FILTERS[$status]));
        $cohort = (clone $accessible)->whereBetween('created_at', [$from, $to]);
        $counts = (clone $cohort)->selectRaw('status, COUNT(*) AS total')->groupBy('status')->pluck('total', 'status');
        $stats = [
            'total' => (int) $counts->sum(),
            'draft' => (int) ($counts['draft'] ?? 0),
            'concremat' => (int) ($counts['awaiting_concremat'] ?? 0),
            'inspector' => (int) ($counts['awaiting_contract'] ?? 0),
            'approved' => (int) ($counts['approved'] ?? 0),
            'delivery_review' => (int) ($counts['awaiting_delivery_review'] ?? 0),
            'delivery_revision' => (int) ($counts['delivery_revision'] ?? 0),
            'delivery_accepted' => (int) ($counts['delivery_accepted'] ?? 0),
            'completed' => (int) ($counts['completed'] ?? 0),
            'returned' => (int) $counts->only(['returned_concremat', 'returned_analyst'])->sum(),
            'returned_analyst' => (int) ($counts['returned_analyst'] ?? 0),
            'returned_concremat' => (int) ($counts['returned_concremat'] ?? 0),
        ];

        // Each OS counts once per action/month, even after resubmission.
        // Event dates are independent of the order's opening date.
        $months = [];
        for ($month = $from->copy()->startOfMonth(); $month->lte($to); $month->addMonth()) {
            $key = $month->format('Y-m');
            $months[$key] = ['month' => $key, 'label' => $month->locale('pt_BR')->translatedFormat('M/y'), 'submitted' => 0, 'approved' => 0, 'opened' => 0, 'completed' => 0, 'completed_on_time' => 0, 'completed_late' => 0, 'unapproved' => 0];
        }
        $seen = [];
        $movements = OsDnitOrderEvent::whereIn('order_id', (clone $accessible)->select('id'))
            ->whereIn('action', ['submitted', 'approved'])->whereBetween('created_at', [$from, $to])
            ->get(['order_id', 'action', 'created_at']);
        foreach ($movements as $event) {
            $month = $event->created_at->timezone(config('app.timezone'))->format('Y-m');
            $key = $month.':'.$event->action.':'.$event->order_id;
            if (isset($months[$month]) && ! isset($seen[$key])) {
                $months[$month][$event->action]++;
                $seen[$key] = true;
            }
        }

        $deadlineStats = array_fill_keys(['completed_on_time', 'completed_late', 'pending_on_time', 'pending_overdue', 'completed_unknown', 'unapproved'], 0);
        foreach ((clone $cohort)->select(['status', 'created_at', 'finalized_at'])->selectSub(OsDnitApprovedDeadline::query(), 'approved_deadline')->cursor() as $order) {
            $months[$order->created_at->timezone(config('app.timezone'))->format('Y-m')]['opened']++;
            $deadlineStats[$this->deadlineOutcome($order)]++;
        }
        foreach ((clone $accessible)->where('status', 'completed')->whereBetween('finalized_at', [$from, $to])
            ->select(['status', 'finalized_at'])->selectSub(OsDnitApprovedDeadline::query(), 'approved_deadline')->cursor() as $order) {
            $month = $order->finalized_at->timezone(config('app.timezone'))->format('Y-m');
            $months[$month]['completed']++;
            $months[$month][$this->deadlineOutcome($order)]++;
        }
        $deadlines = [];
        foreach (['completed_on_time' => 'Finalizadas no prazo', 'completed_late' => 'Finalizadas em atraso',
            'pending_on_time' => 'Ainda no prazo', 'pending_overdue' => 'Prazo vencido', 'completed_unknown' => 'Sem data de conclusão', 'unapproved' => 'Sem prazo aprovado pelo fiscal'] as $key => $label) {
            $deadlines[] = ['key' => $key, 'label' => $label, 'total' => $deadlineStats[$key]];
        }
        $distribution = [];
        $statusGroups = [];
        foreach (OsDnitOrder::STATUS_FILTERS as $key => $states) {
            $distribution[] = ['key' => $key, 'label' => OsDnitOrder::STATUSES[$key], 'total' => (int) $counts->only($states)->sum()];
            foreach ($states as $state) {
                $statusGroups[$state] = $key;
            }
        }

        // Saving a draft/review does not restart the time spent in a stage.
        $stageStart = OsDnitOrderEvent::query()
            ->selectRaw('COALESCE(MAX(created_at), os_dnit_orders.created_at)')
            ->whereColumn('order_id', 'os_dnit_orders.id')
            ->whereIn('action', ['created', 'legacy_draft', 'submitted', 'forwarded', 'returned', 'approved', 'product_delivered', 'delivery_rejected', 'delivery_accepted']);
        $waiting = [];
        foreach (OsDnitOrder::STATUS_FILTERS as $key => $states) {
            if ($key === 'completed') {
                continue;
            }
            $waiting[$key] = ['key' => $key, 'label' => OsDnitOrder::STATUSES[$key], 'total' => 0, 'sum_days' => 0, 'max_days' => 0, 'overdue' => 0, 'sum_overdue_days' => 0, 'max_overdue_days' => 0];
        }
        // Calculate across all filtered pending orders, independently of the list pagination.
        foreach ((clone $cohort)->where('status', '!=', 'completed')->select(['status'])->selectSub(OsDnitApprovedDeadline::query(), 'approved_deadline')->selectSub($stageStart, 'stage_started_at')->cursor() as $order) {
            $key = $statusGroups[$order->status];
            $days = max(0, Carbon::parse($order->stage_started_at)->diffInDays(now(), false));
            $waiting[$key]['total']++;
            $waiting[$key]['sum_days'] += $days;
            $waiting[$key]['max_days'] = max($waiting[$key]['max_days'], $days);
            $deadline = $order->approved_deadline ? Carbon::parse($order->approved_deadline) : null;
            if ($deadline && now()->gt($deadline->copy()->endOfDay())) {
                $overdueDays = $deadline->startOfDay()->diffInDays(today(), false);
                $waiting[$key]['overdue']++;
                $waiting[$key]['sum_overdue_days'] += $overdueDays;
                $waiting[$key]['max_overdue_days'] = max($waiting[$key]['max_overdue_days'], $overdueDays);
            }
        }
        $waiting = array_values(array_map(function ($stage) {
            $stage['average_days'] = $stage['total'] ? round($stage['sum_days'] / $stage['total'], 1) : 0;
            $stage['max_days'] = round($stage['max_days'], 1);
            $stage['average_overdue_days'] = $stage['overdue'] ? round($stage['sum_overdue_days'] / $stage['overdue'], 1) : 0;
            $stage['max_overdue_days'] = (int) $stage['max_overdue_days'];
            unset($stage['sum_days'], $stage['sum_overdue_days']);

            return $stage;
        }, $waiting));
        $pending = (clone $cohort)->where('status', '!=', 'completed')->select([
            'id', 'contract_id', 'request_number', 'subject', 'status', 'highway_name', 'desired_deadline', 'estimated_deadline',
        ])
            ->selectSub($stageStart, 'stage_started_at')->selectSub(OsDnitApprovedDeadline::query(), 'approved_deadline')->with('contract:id,code,name')
            ->orderBy('stage_started_at')->orderBy('id')->paginate(10)->withQueryString();
        $analysisIds = OsDnitWorkflowAccess::pendingOrders($request->user(), $tenant)
            ->whereIn('id', $pending->pluck('id'))->pluck('id');
        $pending->through(function ($order) use ($analysisIds) {
            $since = Carbon::parse($order->stage_started_at);

            return [
                'id' => $order->id, 'request_number' => $order->request_number, 'subject' => $order->subject,
                'status' => $order->status, 'contract' => $order->contract->only(['id', 'code', 'name']),
                'highway_name' => $order->highway_name, 'desired_deadline' => $order->desired_deadline->toDateString(),
                'planned_deadline' => $order->approved_deadline ? Carbon::parse($order->approved_deadline)->toDateString() : null,
                'overdue_days' => $order->approved_deadline ? max(0, (int) Carbon::parse($order->approved_deadline)->startOfDay()->diffInDays(today(), false)) : null,
                'stage_started_at' => $since->toIso8601String(),
                'waiting_days' => max(0, (int) $since->diffInDays(now(), false)),
                'can_analyze' => $analysisIds->contains($order->id) && in_array($order->status, ['awaiting_concremat', 'returned_concremat', 'awaiting_contract'], true),
            ];
        });

        return Inertia::render('Tenant/OsDnit/Dashboard', [
            'contracts' => $contracts, 'highways' => $highways, 'lots' => $lots, 'filters' => $filters,
            'stats' => $stats, 'monthly' => array_values($months), 'pending' => $pending,
            'distribution' => $distribution, 'waiting' => $waiting, 'deadlines' => $deadlines,
            'statusOptions' => array_intersect_key(OsDnitOrder::STATUSES, OsDnitOrder::STATUS_FILTERS),
            'statuses' => OsDnitOrder::STATUSES,
        ]);
    }

    private function deadlineOutcome(OsDnitOrder $order): string
    {
        if (! $order->approved_deadline) {
            return 'unapproved';
        }
        $deadline = Carbon::parse($order->approved_deadline)->endOfDay();
        if ($order->status === 'completed') {
            if (! $order->finalized_at) {
                return 'completed_unknown';
            }

            return $order->finalized_at->lte($deadline) ? 'completed_on_time' : 'completed_late';
        }

        return now()->lte($deadline) ? 'pending_on_time' : 'pending_overdue';
    }
}
