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

        $message = (new MailMessage)
            ->subject("OS {$label}: {$this->ordemServico->codigo}")
            ->greeting("Olá, {$notifiable->name}.")
            ->line("A OS {$this->ordemServico->codigo} foi {$label}.")
            ->line("Título: {$this->ordemServico->titulo}")
            ->line("Contrato: {$this->ordemServico->contract?->code} - {$this->ordemServico->contract?->name}");

        if ($this->decision === 'aprovada' && $isRequester) {
            $message
                ->line('A execução do serviço está autorizada e já pode ser iniciada conforme o escopo aprovado.')
                ->line('Obra: '.($this->ordemServico->obra?->nome ?? 'Não informada'))
                ->line("Aprovado por: {$this->actor->name}");
        }

        if ($this->observation) {
            $message->line("Observação: {$this->observation}");
        }

        $url = $isRequester
            ? route('tenant.ordem-servico.os.index', [
                'tenant' => $this->ordemServico->tenant,
                'contract_id' => $this->ordemServico->contract_id,
            ])
            : route('tenant.ordem-servico.analise.index', $this->ordemServico->tenant);

        return $message->action(
            $this->decision === 'aprovada' && $isRequester ? 'Acessar OS liberada' : 'Acessar OS',
            $url
        );
    }
}
