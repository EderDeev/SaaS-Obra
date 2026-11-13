<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('os_dnit_orders')->where('status', 'delivery_accepted')->whereNull('deleted_at')
            ->orderBy('id')->chunkById(100, function ($orders) {
                foreach ($orders as $candidate) {
                    DB::transaction(function () use ($candidate) {
                        $order = DB::table('os_dnit_orders')->where('id', $candidate->id)
                            ->where('status', 'delivery_accepted')->whereNull('deleted_at')->lockForUpdate()->first();
                        if (! $order) {
                            return;
                        }
                        $delivery = DB::table('os_dnit_order_deliveries')->where('order_id', $order->id)
                            ->orderByDesc('revision')->first();
                        // Complete only when the original requester has actually accepted the latest delivery.
                        if (! $delivery || $delivery->decision !== 'approve' || ! $delivery->reviewed_at
                            || (int) $delivery->reviewed_by_id !== (int) $order->created_by_id) {
                            return;
                        }
                        $version = $order->workflow_version + 1;
                        $snapshot = json_decode(DB::table('os_dnit_order_events')->where('order_id', $order->id)
                            ->orderByDesc('id')->value('snapshot') ?? '{}', true);
                        $snapshot = array_merge($snapshot, ['workflow_version' => $version,
                            'finalized_at' => $delivery->reviewed_at, 'finalized_by_id' => $delivery->reviewed_by_id]);
                        DB::table('os_dnit_orders')->where('id', $order->id)->update([
                            'status' => 'completed', 'finalized_at' => $delivery->reviewed_at,
                            'finalized_by_id' => $delivery->reviewed_by_id, 'workflow_version' => $version, 'updated_at' => now(),
                        ]);
                        DB::table('os_dnit_order_events')->insert([
                            'order_id' => $order->id, 'actor_id' => $delivery->reviewed_by_id,
                            'action' => 'completed', 'from_status' => 'delivery_accepted', 'to_status' => 'completed',
                            'comment' => 'OS finalizada automaticamente com base no aceite da entrega já registrado pelo analista DNIT.',
                            'snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now(),
                        ]);
                    });
                }
            });
    }

    public function down(): void
    {
        // Finalized orders and their audit history must not be reopened by rollback.
    }
};
