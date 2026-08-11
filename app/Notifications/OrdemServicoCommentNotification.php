<?php

namespace App\Notifications;

use App\Models\OrdemServico;
use App\Models\OrdemServicoComentario;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class OrdemServicoCommentNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly OrdemServico $ordemServico,
        private readonly OrdemServicoComentario $comment,
        private readonly User $actor,
    ) {
        $this->ordemServico->loadMissing(['tenant', 'contract', 'obra']);
    }

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        $kind = $this->comment->tipo === 'pendencia' ? 'pendência' : 'comentário';

        return [
            'tenant_id' => $this->ordemServico->tenant_id,
            'contract_id' => $this->ordemServico->contract_id,
            'ordem_servico_id' => $this->ordemServico->id,
            'title' => ucfirst($kind).' na OS',
            'body' => "{$this->actor->name} registrou uma {$kind} na OS {$this->ordemServico->codigo}.",
            'contract' => $this->ordemServico->contract?->code,
            'url' => route('tenant.ordem-servico.os.index', [
                'tenant' => $this->ordemServico->tenant,
                'contract_id' => $this->ordemServico->contract_id,
            ], false),
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $kind = $this->comment->tipo === 'pendencia' ? 'pendência' : 'comentário';
        $headline = ucfirst($kind).' na ordem de serviço';
        $url = route('tenant.ordem-servico.os.index', [
            'tenant' => $this->ordemServico->tenant,
            'contract_id' => $this->ordemServico->contract_id,
        ]);
        $viewData = [
            'ordem' => $this->ordemServico,
            'actor' => $this->actor,
            'notifiable' => $notifiable,
            'headline' => $headline,
            'bodyText' => "{$this->actor->name} registrou uma {$kind} e incluiu você na conversa.",
            'statusLabel' => ucfirst($kind),
            'tone' => $this->comment->tipo === 'pendencia' ? 'warning' : 'info',
            'actionLabel' => 'Acessar conversa',
            'url' => $url,
            'systemUrl' => route('tenant.dashboard', $this->ordemServico->tenant),
            'highlightTitle' => 'Mensagem registrada',
            'highlightBody' => $this->comment->body,
            'observation' => null,
        ];

        return (new MailMessage)
            ->subject("{$headline}: {$this->ordemServico->codigo}")
            ->view('emails.ordem-servico-flow', $viewData)
            ->text('emails.ordem-servico-flow-text', $viewData);
    }
}
