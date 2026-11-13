<?php

namespace App\Support;

use App\Models\OsDnitOrderEvent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class OsDnitApprovedDeadline
{
    public static function query(): Builder
    {
        return OsDnitOrderEvent::query()->select('snapshot->estimated_deadline')
            ->whereColumn('order_id', 'os_dnit_orders.id')->where('action', 'approved')->orderByDesc('id')->limit(1);
    }

    public static function fromEvents(Collection $events, Carbon $asOf): ?Carbon
    {
        $approval = $events->filter(fn ($event) => $event->action === 'approved' && $event->created_at->lte($asOf))
            ->sortBy('id')->last();
        if (! $approval) {
            return null;
        }
        $attributes = $approval->getAttributes();
        $value = array_key_exists('estimated_deadline', $attributes)
            ? $attributes['estimated_deadline'] : ($approval->snapshot['estimated_deadline'] ?? null);

        return $value ? Carbon::parse($value)->endOfDay() : null;
    }
}
