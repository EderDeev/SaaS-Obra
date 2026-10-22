<?php

namespace App\Services;

use App\Models\OsDnitOrder;
use App\Models\User;

class OsDnitOrderHistory
{
    public function record(OsDnitOrder $order, User $actor, string $action, ?string $from, ?string $comment = null): void
    {
        $order->load(['items', 'attachments']);
        $snapshot = $order->only(['request_number', 'supervision_lot', 'highway_name', 'km_start', 'km_end', 'subject',
            'technical_need', 'justification', 'sei_reference', 'review_comment', 'review_items', 'workflow_version']);
        $snapshot['desired_deadline'] = $order->desired_deadline->format('Y-m-d');
        $snapshot['estimated_deadline'] = $order->estimated_deadline?->format('Y-m-d');
        $snapshot['items'] = $order->items->map->only(['product_id', 'product_code', 'product_name', 'unit', 'frequency', 'quantity'])->all();
        $snapshot['attachments'] = $order->attachments->map->only(['id', 'original_name'])->all();
        $event = $order->events()->create(['actor_id' => $actor->id, 'action' => $action, 'from_status' => $from,
            'to_status' => $order->status, 'comment' => $comment, 'snapshot' => $snapshot]);
        app(OsDnitFlowNotifier::class)->enqueue($event);
    }
}
