<?php

namespace App\Notifications;

use App\Models\OrdemServico;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class OrdemServicoLifecycleNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly OrdemServico $ordemServico,
        private readonly User $actor,
        private readonly string $headline,
        private readonly string $bodyText,
        private readonly string $tone = 'info',
    ) {
        $this->ordemServico->loadMissing(['tenant', 'contract', 'obra', 'creator']);
    }

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'tenant_id' => $this->ordemServico->tenant_id,
            'contract_id' => $this->ordemServico->contract_id,
            'ordem_servico_id' => $this->ordemServico->id,
            'title' => $this->headline,
            'body' => $this->bodyText,
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
        $viewData = [
            'ordem' => $this->ordemServico,
            'actor' => $this->actor,
            'notifiable' => $notifiable,
            'headline' => $this->headline,
            'bodyText' => $this->bodyText,
            'statusLabel' => $this->headline,
            'tone' => $this->tone,
            'actionLabel' => 'Acessar ordem de serviço',
            'url' => $url,
            'systemUrl' => route('tenant.dashboard', $this->ordemServico->tenant),
            'highlightTitle' => 'Atualização da OS',
            'highlightBody' => $this->bodyText,
            'observation' => null,
        ];

        return (new MailMessage)
            ->subject("{$this->headline}: {$this->ordemServico->codigo}")
            ->view('emails.ordem-servico-flow', $viewData)
            ->text('emails.ordem-servico-flow-text', $viewData);
    }
}
