<?php

namespace App\Notifications;

use App\Models\OrdemServico;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class OrdemServicoReadyForApprovalNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly OrdemServico $ordemServico,
        private readonly User $actor,
    ) {
        $this->ordemServico->loadMissing(['tenant', 'contract', 'obra']);
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
            'tenant_id' => $this->ordemServico->tenant_id,
            'contract_id' => $this->ordemServico->contract_id,
            'ordem_servico_id' => $this->ordemServico->id,
            'title' => 'OS aguardando aprovação',
            'body' => "{$this->actor->name} analisou a OS {$this->ordemServico->codigo} e enviou para aprovação.",
            'contract' => $this->ordemServico->contract?->code,
            'url' => route('tenant.ordem-servico.analise.index', $this->ordemServico->tenant, false),
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("OS aguardando aprovação: {$this->ordemServico->codigo}")
            ->greeting("Olá, {$notifiable->name}.")
            ->line("A OS {$this->ordemServico->codigo} foi analisada e aguarda sua aprovação.")
            ->line("Título: {$this->ordemServico->titulo}")
            ->line("Contrato: {$this->ordemServico->contract?->code} - {$this->ordemServico->contract?->name}")
            ->action('Acessar aprovação da OS', route('tenant.ordem-servico.analise.index', $this->ordemServico->tenant));
    }
}
