<?php

namespace App\Notifications;

use App\Models\RelatorioNaoConformidade;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

class RncResponsibleNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly RelatorioNaoConformidade $rnc,
        private readonly User $actor,
    ) {
        $this->rnc->loadMissing(['tenant', 'contract', 'obra']);
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
            'tenant_id' => $this->rnc->tenant_id,
            'contract_id' => $this->rnc->contract_id,
            'rnc_id' => $this->rnc->id,
            'title' => 'RNC aguardando resposta',
            'body' => "{$this->actor->name} notificou os responsaveis pela RNC {$this->rnc->formatted_number}.",
            'contract' => $this->rnc->contract?->code,
            'url' => route('tenant.qualidade.rnc.show', [$this->rnc->tenant, $this->rnc], false),
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $descricao = Str::limit(
            trim(strip_tags((string) $this->rnc->descricao_problema)),
            450,
        );
        $rncUrl = route('tenant.qualidade.rnc.show', [$this->rnc->tenant, $this->rnc]);
        $systemUrl = route('tenant.dashboard', $this->rnc->tenant);

        return (new MailMessage)
            ->subject("RNC {$this->rnc->formatted_number} aguardando resposta")
            ->view('emails.rnc-responsible', [
                'rnc' => $this->rnc,
                'actor' => $this->actor,
                'notifiable' => $notifiable,
                'descricao' => $descricao,
                'url' => $rncUrl,
                'systemUrl' => $systemUrl,
            ])
            ->text('emails.rnc-responsible-text', [
                'rnc' => $this->rnc,
                'actor' => $this->actor,
                'notifiable' => $notifiable,
                'descricao' => $descricao,
                'url' => $rncUrl,
                'systemUrl' => $systemUrl,
            ]);
    }
}
