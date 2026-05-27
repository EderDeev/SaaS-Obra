<?php

namespace App\Notifications;

use App\Models\RelatorioNaoConformidadeEvidencia;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class RncEvidenceSubmittedNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly RelatorioNaoConformidadeEvidencia $evidencia,
        private readonly User $actor,
    ) {
        $this->evidencia->loadMissing([
            'tenant',
            'rnc.tenant',
            'rnc.contract',
            'rnc.obra',
            'rnc.contratante',
            'rnc.contratada',
            'acaoCorretiva',
            'photos',
        ]);
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
        $rnc = $this->evidencia->rnc;

        return [
            'tenant_id' => $this->evidencia->tenant_id,
            'contract_id' => $rnc->contract_id,
            'rnc_id' => $rnc->id,
            'title' => 'RNC finalizada',
            'body' => "{$this->actor->name} enviou as evidencias da correcao da RNC {$rnc->formatted_number}.",
            'contract' => $rnc->contract?->code,
            'url' => route('tenant.qualidade.rnc.show', [$rnc->tenant, $rnc], false),
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $rnc = $this->evidencia->rnc;
        $rncUrl = route('tenant.qualidade.rnc.show', [$rnc->tenant, $rnc]);
        $systemUrl = route('tenant.dashboard', $rnc->tenant);

        return (new MailMessage)
            ->subject("RNC {$rnc->formatted_number} finalizada")
            ->view('emails.rnc-evidence-submitted', [
                'rnc' => $rnc,
                'evidencia' => $this->evidencia,
                'actor' => $this->actor,
                'notifiable' => $notifiable,
                'url' => $rncUrl,
                'systemUrl' => $systemUrl,
            ])
            ->text('emails.rnc-evidence-submitted-text', [
                'rnc' => $rnc,
                'evidencia' => $this->evidencia,
                'actor' => $this->actor,
                'notifiable' => $notifiable,
                'url' => $rncUrl,
                'systemUrl' => $systemUrl,
            ]);
    }
}
