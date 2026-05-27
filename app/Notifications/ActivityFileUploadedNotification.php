<?php

namespace App\Notifications;

use App\Models\Activity;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class ActivityFileUploadedNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly Activity $activity,
        private readonly User $actor,
        private readonly string $fileName,
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
            'title' => 'Novo arquivo na atividade',
            'body' => "{$this->actor->name} anexou \"{$this->fileName}\" em \"{$this->activity->title}\".",
            'contract' => $this->activity->contract?->code,
            'url' => route('tenant.activities.index', $this->activity->tenant, false),
        ];
    }
}
