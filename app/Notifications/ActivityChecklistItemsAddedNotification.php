<?php

namespace App\Notifications;

use App\Models\Activity;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

class ActivityChecklistItemsAddedNotification extends Notification
{
    use Queueable;

    /**
     * @param  array<int, string>  $items
     */
    public function __construct(
        private readonly Activity $activity,
        private readonly User $actor,
        private readonly array $items,
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
            'title' => 'Novas etapas no checklist',
            'body' => "{$this->actor->name} adicionou {$this->itemsCountLabel()} em \"{$this->activity->title}\".",
            'contract' => $this->activity->contract?->code,
            'url' => route('tenant.activities.index', $this->activity->tenant, false),
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Checklist atualizado: {$this->activity->title}")
            ->view('emails.activity-event', $this->mailData($notifiable))
            ->text('emails.activity-event-text', $this->mailData($notifiable));
    }

    /**
     * @return array<string, mixed>
     */
    private function mailData(object $notifiable): array
    {
        return [
            'activity' => $this->activity,
            'notifiable' => $notifiable,
            'title' => 'Checklist atualizado',
            'intro' => "{$this->actor->name} adicionou {$this->itemsCountLabel()} a uma atividade em que voce esta envolvido.",
            'eventLabel' => 'Novas etapas',
            'eventBody' => Str::limit(implode(' | ', $this->items), 240),
            'description' => Str::limit(trim(strip_tags((string) $this->activity->description)), 450),
            'url' => route('tenant.activities.index', $this->activity->tenant),
            'systemUrl' => route('tenant.dashboard', $this->activity->tenant),
        ];
    }

    private function itemsCountLabel(): string
    {
        $count = count($this->items);

        return $count === 1 ? '1 nova etapa' : "{$count} novas etapas";
    }
}
