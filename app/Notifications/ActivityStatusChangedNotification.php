<?php

namespace App\Notifications;

use App\Models\Activity;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class ActivityStatusChangedNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly Activity $activity,
        private readonly User $actor,
        private readonly string $oldStatus,
        private readonly string $newStatus,
    ) {
        $this->activity->loadMissing(['tenant', 'contract']);
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'tenant_id' => $this->activity->tenant_id,
            'contract_id' => $this->activity->contract_id,
            'activity_id' => $this->activity->id,
            'title' => 'Status da atividade alterado',
            'body' => "{$this->actor->name} moveu \"{$this->activity->title}\" de {$this->statusLabel($this->oldStatus)} para {$this->statusLabel($this->newStatus)}.",
            'contract' => $this->activity->contract?->code,
            'url' => route('tenant.activities.index', $this->activity->tenant, false),
        ];
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'todo' => 'A fazer',
            'in_progress' => 'Em andamento',
            'review' => 'Em revisão',
            'done' => 'Concluídas',
            default => $status,
        };
    }
}
