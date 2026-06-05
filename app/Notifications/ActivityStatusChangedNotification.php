<?php

namespace App\Notifications;

use App\Models\Activity;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

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
            'title' => 'Status da atividade alterado',
            'body' => "{$this->actor->name} moveu \"{$this->activity->title}\" de {$this->statusLabel($this->oldStatus)} para {$this->statusLabel($this->newStatus)}.",
            'contract' => $this->activity->contract?->code,
            'url' => route('tenant.activities.index', $this->activity->tenant, false),
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Status da atividade alterado: {$this->activity->title}")
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
        $oldStatus = $this->statusLabel($this->oldStatus);
        $newStatus = $this->statusLabel($this->newStatus);

        return [
            'activity' => $this->activity,
            'notifiable' => $notifiable,
            'title' => 'Status da atividade alterado',
            'intro' => "{$this->actor->name} atualizou o status de uma atividade vinculada a voce.",
            'eventLabel' => 'Alteracao',
            'eventBody' => "{$oldStatus} -> {$newStatus}",
            'description' => $description,
            'url' => $activityUrl,
            'systemUrl' => $systemUrl,
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
