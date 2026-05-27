<?php

namespace App\Notifications;

use App\Models\RelatorioNaoConformidadeAcaoCorretiva;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

class RncCorrectiveActionSubmittedNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly RelatorioNaoConformidadeAcaoCorretiva $acaoCorretiva,
        private readonly User $actor,
    ) {
        $this->acaoCorretiva->loadMissing(['tenant', 'rnc.tenant', 'rnc.contract', 'rnc.obra']);
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
        $rnc = $this->acaoCorretiva->rnc;
        $prazo = $this->acaoCorretiva->prazo_execucao_proposto?->format('d/m/Y');

        return [
            'tenant_id' => $this->acaoCorretiva->tenant_id,
            'contract_id' => $rnc->contract_id,
            'rnc_id' => $rnc->id,
            'title' => 'Proposta de acao corretiva enviada',
            'body' => "{$this->actor->name} enviou uma proposta para a RNC {$rnc->formatted_number} com prazo proposto em {$prazo}.",
            'contract' => $rnc->contract?->code,
            'url' => route('tenant.qualidade.rnc.show', [$rnc->tenant, $rnc], false),
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $rnc = $this->acaoCorretiva->rnc;
        $descricao = Str::limit(
            trim(strip_tags((string) $this->acaoCorretiva->descricao_proposta)),
            450,
        );
        $rncUrl = route('tenant.qualidade.rnc.show', [$rnc->tenant, $rnc]);
        $systemUrl = route('tenant.dashboard', $rnc->tenant);

        return (new MailMessage)
            ->subject("Proposta de acao corretiva - RNC {$rnc->formatted_number}")
            ->view('emails.rnc-corrective-action-submitted', [
                'rnc' => $rnc,
                'acaoCorretiva' => $this->acaoCorretiva,
                'actor' => $this->actor,
                'notifiable' => $notifiable,
                'descricao' => $descricao,
                'url' => $rncUrl,
                'systemUrl' => $systemUrl,
            ])
            ->text('emails.rnc-corrective-action-submitted-text', [
                'rnc' => $rnc,
                'acaoCorretiva' => $this->acaoCorretiva,
                'actor' => $this->actor,
                'notifiable' => $notifiable,
                'descricao' => $descricao,
                'url' => $rncUrl,
                'systemUrl' => $systemUrl,
            ]);
    }
}
