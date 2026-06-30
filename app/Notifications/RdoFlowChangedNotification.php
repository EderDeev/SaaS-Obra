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
        $this->rdo->loadMissing(['contract', 'configuracao.obras']);
        $rdoUrl = route('tenant.diario-obra.rdo.show', [$this->tenant->slug, $this->rdo->id]);
        $viewData = [
            'notifiable' => $notifiable,
            'rdo' => $this->rdo,
            'actor' => $this->actor,
            'bodyText' => $this->message,
            'statusLabel' => $this->statusLabel(),
            'rdoUrl' => $rdoUrl,
        ];

        return (new MailMessage)
            ->subject("RDO {$this->rdo->code} - atualização no fluxo")
            ->view('emails.rdo-flow-changed', $viewData)
            ->text('emails.rdo-flow-changed-text', $viewData);
    }

    private function statusLabel(): string
    {
        return match ($this->rdo->status) {
            'rascunho' => 'Rascunho',
            'em_aprovacao' => 'Em aprovação',
            'devolvido_construtora' => 'Devolvido à construtora',
            'pendente_comprovacao' => 'Pendente de comprovação',
            'arquivado' => 'Aprovado e arquivado',
            default => ucfirst(str_replace('_', ' ', (string) $this->rdo->status)),
        };
    }
}
