<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\OsDnitOrder;
use App\Models\Tenant;
use App\Services\OsDnitStageTimeline;
use App\Support\OsDnitContractAccess;
use App\Support\OsDnitPermissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class OsDnitStageDashboardController extends Controller
{
    public function __invoke(Request $request, Tenant $tenant, OsDnitStageTimeline $timeline): JsonResponse
    {
        $contracts = OsDnitContractAccess::configuredContracts($request->user(), $tenant, OsDnitPermissions::DASHBOARD)->pluck('contracts.id');
        $filters = $request->validate([
            'contract_id' => ['nullable', 'integer', Rule::in($contracts->all())],
            'stage' => ['required', Rule::in(array_keys(OsDnitStageTimeline::STAGES))],
            'date_from' => ['required', 'date_format:Y-m-d'],
            'date_to' => ['required', 'date_format:Y-m-d', 'after_or_equal:date_from'],
        ]);
        $from = Carbon::parse($filters['date_from'])->startOfDay();
        $to = Carbon::parse($filters['date_to'])->endOfDay();
        if ($from->copy()->startOfMonth()->diffInMonths($to->copy()->startOfMonth()) >= 24) {
            throw ValidationException::withMessages(['date_from' => 'Selecione um período de até 24 meses.']);
        }
        $asOf = now();
        $items = [];
        OsDnitOrder::where('tenant_id', $tenant->id)->whereIn('contract_id', $contracts)
            ->when($filters['contract_id'] ?? null, fn ($query, $id) => $query->where('contract_id', $id))
            ->where('created_at', '<=', $to)
            ->select(['id', 'contract_id', 'status', 'request_number', 'subject', 'created_at', 'desired_deadline', 'estimated_deadline'])
            ->with(['contract:id,code', 'events' => fn ($query) => $query->select([
                'id', 'order_id', 'actor_id', 'action', 'from_status', 'to_status', 'created_at',
                'snapshot->estimated_deadline as estimated_deadline', 'snapshot->desired_deadline as desired_deadline', 'snapshot->deliveries as deliveries_snapshot',
            ])->with('actor:id,name'), 'deliveries'])
            ->chunkById(100, function ($orders) use (&$items, $timeline, $filters, $from, $to, $asOf, $tenant) {
                foreach ($orders as $order) {
                    $cycle = collect($timeline->cycles($order, $asOf))->filter(fn ($cycle) => $cycle['stage'] === $filters['stage']
                        && Carbon::parse($cycle['started_at'])->betweenIncluded($from, $to))->last();
                    if (! $cycle) {
                        continue;
                    }
                    $deadline = $cycle['deadline_at'] ? Carbon::parse($cycle['deadline_at']) : null;
                    $finished = $cycle['finished_at'] ? Carbon::parse($cycle['finished_at']) : null;
                    $outcome = ! $deadline ? 'unapproved' : (($finished ?? $asOf)->gt($deadline) ? 'missed' : ($finished ? 'attended' : 'pending'));
                    $items[] = [...$cycle, 'id' => $order->id, 'number' => $order->request_number, 'subject' => $order->subject,
                        'contract' => $order->contract->code, 'outcome' => $outcome,
                        'url' => route('tenant.os-dnit.orders.show', [$tenant->slug, $order->id])];
                }
            });

        return response()->json([
            'items' => collect($items)->sortByDesc('started_at')->values(),
            'totals' => ['attended' => collect($items)->where('outcome', 'attended')->count(),
                'missed' => collect($items)->where('outcome', 'missed')->count(), 'pending' => collect($items)->where('outcome', 'pending')->count(),
                'unapproved' => collect($items)->where('outcome', 'unapproved')->count()],
            'calculated_at' => $asOf->toIso8601String(), 'stages' => OsDnitStageTimeline::STAGES,
            'deadline_reference' => 'Prazo aprovado pelo fiscal do contrato, preservado no registro da aprovação. A data limite inclui o dia inteiro.',
        ])->header('Cache-Control', 'private, no-store');
    }
}
