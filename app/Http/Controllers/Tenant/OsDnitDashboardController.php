<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\OsDnitHighway;
use App\Models\OsDnitOrder;
use App\Models\OsDnitOrderEvent;
use App\Models\OsDnitSupervisionLot;
use App\Models\Tenant;
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
        $contracts = OsDnitContractAccess::contracts($request->user(), $tenant, OsDnitPermissions::DASHBOARD)->orderBy('code')->get(['id', 'code', 'name']);
        $request->merge([
            'date_from' => $request->input('date_from') ?? now()->startOfMonth()->subMonths(11)->toDateString(),
            'date_to' => $request->input('date_to') ?? now()->toDateString(),
        ]);
        $filters = $request->validate([
            'contract_id' => ['nullable', 'integer', Rule::in($contracts->pluck('id')->all())],
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
            ->when($filters['highway_id'] ?? null, fn ($query, $id) => $query->where('highway_id', $id));
        $cohort = (clone $accessible)->whereBetween('created_at', [$from, $to]);
        $counts = (clone $cohort)->selectRaw('status, COUNT(*) AS total')->groupBy('status')->pluck('total', 'status');
        $stats = [
            'total' => (int) $counts->sum(),
            'draft' => (int) ($counts['draft'] ?? 0),
            'concremat' => (int) ($counts['awaiting_concremat'] ?? 0),
            'inspector' => (int) ($counts['awaiting_contract'] ?? 0),
            'approved' => (int) ($counts['approved'] ?? 0),
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
            $months[$key] = ['month' => $key, 'label' => $month->locale('pt_BR')->translatedFormat('M/y'), 'submitted' => 0, 'approved' => 0];
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

        // Saving a draft/review does not restart the time spent in a stage.
        $stageStart = OsDnitOrderEvent::query()
            ->selectRaw('COALESCE(MAX(created_at), os_dnit_orders.created_at)')
            ->whereColumn('order_id', 'os_dnit_orders.id')
            ->whereIn('action', ['created', 'legacy_draft', 'submitted', 'forwarded', 'returned', 'approved']);
        $pending = (clone $cohort)->where('status', '!=', 'completed')->select([
            'id', 'contract_id', 'request_number', 'subject', 'status', 'highway_name', 'desired_deadline',
        ])
            ->selectSub($stageStart, 'stage_started_at')->with('contract:id,code,name')
            ->orderBy('stage_started_at')->orderBy('id')->paginate(10)->withQueryString();
        $analysisIds = OsDnitWorkflowAccess::pendingOrders($request->user(), $tenant)
            ->whereIn('id', $pending->pluck('id'))->pluck('id');
        $pending->through(function ($order) use ($analysisIds) {
            $since = Carbon::parse($order->stage_started_at);

            return [
                'id' => $order->id, 'request_number' => $order->request_number, 'subject' => $order->subject,
                'status' => $order->status, 'contract' => $order->contract->only(['id', 'code', 'name']),
                'highway_name' => $order->highway_name, 'desired_deadline' => $order->desired_deadline->toDateString(),
                'stage_started_at' => $since->toIso8601String(),
                'waiting_days' => max(0, (int) $since->diffInDays(now(), false)),
                'can_analyze' => $analysisIds->contains($order->id),
            ];
        });

        return Inertia::render('Tenant/OsDnit/Dashboard', [
            'contracts' => $contracts, 'highways' => $highways, 'lots' => $lots, 'filters' => $filters,
            'stats' => $stats, 'monthly' => array_values($months), 'pending' => $pending,
            'statuses' => OsDnitOrder::STATUSES,
        ]);
    }
}
