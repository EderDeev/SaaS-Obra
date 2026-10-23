<?php

namespace App\Services;

use App\Models\OsDnitOrderEvent;
use App\Notifications\OsDnitFlowChangedNotification;
use Illuminate\Support\Facades\Notification;

class OsDnitFlowNotifier
{
    public function enqueue(OsDnitOrderEvent $event): void
    {
        $recipients = app(OsDnitFlowRecipients::class)->forEvent($event);
        if ($recipients->isEmpty()) {
            return;
        }

        // The dedicated database queue is part of the same transaction as this event.
        // Workers can only see committed jobs; failed transitions roll the jobs back too.
        Notification::send($recipients, new OsDnitFlowChangedNotification($event->id));
    }
}
