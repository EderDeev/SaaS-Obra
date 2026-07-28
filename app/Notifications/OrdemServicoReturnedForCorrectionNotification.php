<?php

namespace App\Notifications;

use App\Models\OrdemServico;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class OrdemServicoReturnedForCorrectionNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly OrdemServico $ordemServico,
        private readonly User $actor,
        private readonly string $stageLabel,
        private readonly string $reason,
    ) {
        $this->ordemServico->loadMissing(['tenant', 'contract', 'obra', 'creator']);
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
            'title' => 'OS devolvida para correção',
            'body' => "{$this->actor->name} reprovou a OS {$this->ordemServico->codigo} durante a {$this->stageLabel}.",
            'contract' => $this->ordemServico->contract?->code,
            'url' => route('tenant.ordem-servico.os.index', [
                'tenant' => $this->ordemServico->tenant,
                'contract_id' => $this->ordemServico->contract_id,
            ], false),
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = route('tenant.ordem-servico.os.index', [
            'tenant' => $this->ordemServico->tenant,
            'contract_id' => $this->ordemServico->contract_id,
        ]);
        $systemUrl = route('tenant.dashboard', $this->ordemServico->tenant);
        $viewData = [
            'ordem' => $this->ordemServico,
            'actor' => $this->actor,
            'notifiable' => $notifiable,
            'headline' => 'OS devolvida para correção',
            'bodyText' => "{$this->actor->name} reprovou esta ordem de serviço durante a {$this->stageLabel}.",
            'statusLabel' => 'Rascunho',
            'tone' => 'danger',
            'actionLabel' => 'Acessar OS para correção',
            'url' => $url,
            'systemUrl' => $systemUrl,
            'highlightTitle' => 'Correção necessária',
            'highlightBody' => 'A OS voltou para rascunho e pode ser corrigida antes de uma nova submissão.',
            'observation' => $this->reason,
        ];

        return (new MailMessage)
            ->subject("OS devolvida para correção: {$this->ordemServico->codigo}")
            ->view('emails.ordem-servico-flow', $viewData)
            ->text('emails.ordem-servico-flow-text', $viewData);
    }
}
