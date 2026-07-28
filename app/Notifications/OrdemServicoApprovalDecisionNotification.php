<?php

namespace App\Notifications;

use App\Models\OrdemServico;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class OrdemServicoApprovalDecisionNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly OrdemServico $ordemServico,
        private readonly User $actor,
        private readonly string $decision,
        private readonly ?string $observation = null,
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
        $label = $this->decision === 'aprovada' ? 'aprovada' : 'recusada';
        $isRequester = (int) $notifiable->id === (int) $this->ordemServico->created_by_id;
        $body = $this->decision === 'aprovada' && $isRequester
            ? "A OS {$this->ordemServico->codigo} foi aprovada. A execução do serviço está autorizada."
            : "{$this->actor->name} marcou a OS {$this->ordemServico->codigo} como {$label}.";

        return [
            'tenant_id' => $this->ordemServico->tenant_id,
            'contract_id' => $this->ordemServico->contract_id,
            'ordem_servico_id' => $this->ordemServico->id,
            'title' => "OS {$label}",
            'body' => $body,
            'contract' => $this->ordemServico->contract?->code,
            'url' => $isRequester
                ? route('tenant.ordem-servico.os.index', [
                    'tenant' => $this->ordemServico->tenant,
                    'contract_id' => $this->ordemServico->contract_id,
                ], false)
                : route('tenant.ordem-servico.analise.index', $this->ordemServico->tenant, false),
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $label = $this->decision === 'aprovada' ? 'aprovada' : 'recusada';
        $isRequester = (int) $notifiable->id === (int) $this->ordemServico->created_by_id;

        $url = $isRequester
            ? route('tenant.ordem-servico.os.index', [
                'tenant' => $this->ordemServico->tenant,
                'contract_id' => $this->ordemServico->contract_id,
            ])
            : route('tenant.ordem-servico.analise.index', $this->ordemServico->tenant);
        $systemUrl = route('tenant.dashboard', $this->ordemServico->tenant);
        $approved = $this->decision === 'aprovada';
        $viewData = [
            'ordem' => $this->ordemServico,
            'actor' => $this->actor,
            'notifiable' => $notifiable,
            'headline' => $approved ? 'OS aprovada' : 'OS recusada',
            'bodyText' => $approved
                ? "{$this->actor->name} aprovou esta ordem de serviço."
                : "{$this->actor->name} recusou esta ordem de serviço.",
            'statusLabel' => $approved ? 'Aprovada' : 'Recusada',
            'tone' => $approved ? 'success' : 'danger',
            'actionLabel' => $approved && $isRequester ? 'Acessar OS liberada' : 'Acessar OS',
            'url' => $url,
            'systemUrl' => $systemUrl,
            'highlightTitle' => $approved ? 'Execução autorizada' : 'Ajustes necessários',
            'highlightBody' => $approved
                ? 'A execução do serviço está autorizada e já pode ser iniciada conforme o escopo aprovado.'
                : 'Consulte a observação da decisão, ajuste a ordem de serviço e acompanhe as próximas orientações no sistema.',
            'observation' => $this->observation,
        ];

        return (new MailMessage)
            ->subject("OS {$label}: {$this->ordemServico->codigo}")
            ->view('emails.ordem-servico-flow', $viewData)
            ->text('emails.ordem-servico-flow-text', $viewData);
    }
}
