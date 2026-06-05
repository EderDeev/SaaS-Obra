<?php

namespace App\Notifications;

use App\Models\Activity;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

class ActivityCommentedNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly Activity $activity,
        private readonly User $actor,
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
            'title' => 'Novo comentário na atividade',
            'body' => "{$this->actor->name} comentou em \"{$this->activity->title}\".",
            'contract' => $this->activity->contract?->code,
            'url' => route('tenant.activities.index', $this->activity->tenant, false),
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Novo comentario na atividade: {$this->activity->title}")
            ->view('emails.activity-event', $this->mailData($notifiable))
            ->text('emails.activity-event-text', $this->mailData($notifiable));
    }

    /**
     * @return array<string, mixed>
     */
    private function mailData(object $notifiable): array
    {
        $activityUrl = route('tenant.activities.index', $this->activity->tenant);
        $systemUrl = route('tenant.dashboard', $this->activity->tenant);
        $description = Str::limit(trim(strip_tags((string) $this->activity->description)), 450);

        return [
            'activity' => $this->activity,
            'notifiable' => $notifiable,
            'title' => 'Novo comentario na atividade',
            'intro' => "{$this->actor->name} comentou em uma atividade vinculada a voce.",
            'eventLabel' => 'Comentario',
            'eventBody' => "{$this->actor->name} comentou em \"{$this->activity->title}\".",
            'description' => $description,
            'url' => $activityUrl,
            'systemUrl' => $systemUrl,
        ];
    }
}
