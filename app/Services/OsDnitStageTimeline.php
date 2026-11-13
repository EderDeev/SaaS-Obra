<?php

namespace App\Services;

use App\Models\OsDnitOrder;
use App\Models\OsDnitOrderEvent;
use App\Support\OsDnitApprovedDeadline;
use Illuminate\Support\Carbon;

class OsDnitStageTimeline
{
    public const STAGES = [
        'demand' => 'Demanda',
        'supervision' => 'Análise Supervisão',
        'inspector' => 'Fiscal do contrato',
        'delivery' => 'Entrega do produto',
        'evaluation' => 'Avaliação do analista DNIT',
    ];

    public function cycles(OsDnitOrder $order, Carbon $asOf): array
    {
        $cycles = [];
        $stage = 'demand';
        $startedAt = $order->created_at;
        $entry = null;
        foreach ($order->events->sortBy('id') as $event) {
            if ($event->created_at->gt($asOf)) {
                continue;
            }
            $next = $this->stage($event->to_status);
            // Saving an analysis does not restart the stage clock.
            if ($next === $stage) {
                continue;
            }
            if ($stage !== null) {
                $cycles[] = $this->cycle($order, $stage, $startedAt, $event->created_at, $entry, $event, $asOf);
            }
            $stage = $next;
            $startedAt = $event->created_at;
            $entry = $event;
        }
        if ($stage !== null && ($entry !== null || $order->status === 'draft')) {
            $cycles[] = $this->cycle($order, $stage, $startedAt, null, $entry, null, $asOf);
        }

        return $cycles;
    }

    private function stage(string $status): ?string
    {
        return match ($status) {
            'draft', 'returned_analyst' => 'demand',
            'awaiting_concremat', 'returned_concremat' => 'supervision',
            'awaiting_contract' => 'inspector',
            'approved', 'delivery_revision' => 'delivery',
            'awaiting_delivery_review' => 'evaluation',
            default => null,
        };
    }

    private function cycle(OsDnitOrder $order, string $stage, Carbon $start, ?Carbon $end, ?OsDnitOrderEvent $entry, ?OsDnitOrderEvent $exit, Carbon $asOf): array
    {
        $event = $stage === 'delivery' ? $exit : $entry;
        $references = $event?->deliveries_snapshot;
        if (is_string($references)) {
            $references = json_decode($references, true);
        }
        $deliveryId = is_array($references) && $references ? end($references)['id'] : null;
        $delivery = in_array($stage, ['delivery', 'evaluation'], true)
            ? ($deliveryId ? $order->deliveries->firstWhere('id', $deliveryId) : $order->deliveries->filter(fn ($delivery) => $delivery->created_at->lte($stage === 'delivery' ? ($end ?? $asOf) : $start))
                ->sortByDesc('revision')->first()
            ) : null;
        // An ongoing delivery stage must not show the file from a previous rejected revision.
        if ($stage === 'delivery' && ($end === null || ($delivery && $delivery->created_at->lt($start)))) {
            $delivery = null;
        }
        $evaluatedAt = $delivery?->reviewed_at && $delivery->reviewed_at->lte($asOf) ? $delivery->reviewed_at : null;
        $deadline = OsDnitApprovedDeadline::fromEvents($order->events, $asOf);

        return [
            'stage' => $stage, 'started_at' => $start->toIso8601String(), 'finished_at' => $end?->toIso8601String(),
            'elapsed_minutes' => max(0, (int) $start->diffInMinutes($end ?? $asOf, false)),
            'deadline_at' => $deadline?->toIso8601String(),
            'responsible' => $exit?->actor?->name,
            'revision' => $delivery?->revision,
            'delivered_at' => $delivery?->created_at?->toIso8601String(),
            'evaluated_at' => $evaluatedAt?->toIso8601String(),
            'evaluation_minutes' => $delivery ? max(0, (int) $delivery->created_at->diffInMinutes($evaluatedAt ?? $asOf, false)) : null,
            'evaluation_result' => $evaluatedAt ? $delivery->decision : null,
        ];
    }
}
