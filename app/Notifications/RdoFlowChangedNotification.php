<?php

namespace App\Notifications;

use App\Models\RdoDiario;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class RdoFlowChangedNotification extends Notification
{
    use Queueable;

    public function __construct(
        public RdoDiario $rdo,
        public Tenant $tenant,
        public User $actor,
        public string $message,
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'rdo_flow_changed',
            'title' => "RDO {$this->rdo->code}",
            'body' => $this->message,
            'rdo_id' => $this->rdo->id,
            'tenant_id' => $this->rdo->tenant_id,
            'actor_id' => $this->actor->id,
            'url' => route('tenant.diario-obra.rdo.show', [$this->tenant->slug, $this->rdo->id]),
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("RDO {$this->rdo->code} - atualização no fluxo")
            ->greeting("Olá, {$notifiable->name}!")
            ->line($this->message)
            ->line("Ação realizada por {$this->actor->name}.")
            ->action('Acessar RDO', route('tenant.diario-obra.rdo.show', [$this->tenant->slug, $this->rdo->id]));
    }
}
