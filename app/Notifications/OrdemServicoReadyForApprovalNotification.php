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
        $url = route('tenant.ordem-servico.analise.index', $this->ordemServico->tenant);
        $systemUrl = route('tenant.dashboard', $this->ordemServico->tenant);
        $viewData = [
            'ordem' => $this->ordemServico,
            'actor' => $this->actor,
            'notifiable' => $notifiable,
            'headline' => 'OS aguardando aprovação',
            'bodyText' => "{$this->actor->name} concluiu a análise e encaminhou esta ordem de serviço para sua aprovação.",
            'statusLabel' => 'Em aprovação',
            'tone' => 'primary',
            'actionLabel' => 'Avaliar aprovação',
            'url' => $url,
            'systemUrl' => $systemUrl,
            'highlightTitle' => 'Decisão necessária',
            'highlightBody' => 'Confira a análise registrada e decida pela aprovação ou recusa da ordem de serviço.',
            'observation' => $this->ordemServico->analysis_observation,
        ];

        return (new MailMessage)
            ->subject("OS aguardando aprovação: {$this->ordemServico->codigo}")
            ->view('emails.ordem-servico-flow', $viewData)
            ->text('emails.ordem-servico-flow-text', $viewData);
    }
}
