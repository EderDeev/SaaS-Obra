<?php

namespace App\Notifications;

use App\Models\Activity;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

class ActivityAssignedNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly Activity $activity,
        private readonly User $creator,
    ) {
        $this->activity->loadMissing(['tenant', 'contract']);
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
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
            'title' => 'Nova atividade atribuída',
            'body' => "{$this->creator->name} atribuiu a atividade \"{$this->activity->title}\" para você.",
            'contract' => $this->activity->contract?->code,
            'url' => route('tenant.activities.index', $this->activity->tenant, false),
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $description = Str::limit(
            trim(strip_tags((string) $this->activity->description)),
            450,
        );
        $activityUrl = route('tenant.activities.index', $this->activity->tenant);
        $systemUrl = route('tenant.dashboard', $this->activity->tenant);

        return (new MailMessage)
            ->subject("Nova atividade atribuida: {$this->activity->title}")
            ->view('emails.activity-assigned', [
                'activity' => $this->activity,
                'creator' => $this->creator,
                'notifiable' => $notifiable,
                'description' => $description,
                'priorityLabel' => $this->priorityLabel($this->activity->priority),
                'url' => $activityUrl,
                'systemUrl' => $systemUrl,
            ])
            ->text('emails.activity-assigned-text', [
                'activity' => $this->activity,
                'creator' => $this->creator,
                'notifiable' => $notifiable,
                'description' => $description,
                'priorityLabel' => $this->priorityLabel($this->activity->priority),
                'url' => $activityUrl,
                'systemUrl' => $systemUrl,
            ]);
    }

    private function priorityLabel(string $priority): string
    {
        return match ($priority) {
            'low' => 'Baixa',
            'normal' => 'Normal',
            'high' => 'Alta',
            'urgent' => 'Urgente',
            default => $priority,
        };
    }
}
