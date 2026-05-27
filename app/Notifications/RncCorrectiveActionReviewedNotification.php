<?php

namespace App\Notifications;

use App\Models\RelatorioNaoConformidadeAcaoCorretiva;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

class RncCorrectiveActionReviewedNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly RelatorioNaoConformidadeAcaoCorretiva $acaoCorretiva,
        private readonly User $actor,
    ) {
        $this->acaoCorretiva->loadMissing(['tenant', 'rnc.tenant', 'rnc.contract', 'rnc.obra', 'user', 'reviewer']);
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
        $approved = $this->acaoCorretiva->status === 'approved';
        $prazo = $this->acaoCorretiva->prazo_execucao_proposto?->format('d/m/Y');

        return [
            'tenant_id' => $this->acaoCorretiva->tenant_id,
            'contract_id' => $rnc->contract_id,
            'rnc_id' => $rnc->id,
            'title' => $approved ? 'Proposta de acao corretiva aprovada' : 'Proposta de acao corretiva reprovada',
            'body' => $approved
                ? "A proposta da RNC {$rnc->formatted_number} foi aceita. O processo corretivo pode ser iniciado ate {$prazo}."
                : "A proposta da RNC {$rnc->formatted_number} foi reprovada. Motivo: {$this->acaoCorretiva->review_observation}",
            'contract' => $rnc->contract?->code,
            'url' => route(
                $approved ? 'tenant.qualidade.rnc.show' : 'tenant.qualidade.rnc.acao-corretiva.create',
                [$rnc->tenant, $rnc],
                false,
            ),
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $rnc = $this->acaoCorretiva->rnc;
        $approved = $this->acaoCorretiva->status === 'approved';
        $observacao = Str::limit(
            trim(strip_tags((string) $this->acaoCorretiva->review_observation)),
            600,
        );
        $actionUrl = route(
            $approved ? 'tenant.qualidade.rnc.show' : 'tenant.qualidade.rnc.acao-corretiva.create',
            [$rnc->tenant, $rnc],
        );
        $systemUrl = route('tenant.dashboard', $rnc->tenant);

        return (new MailMessage)
            ->subject(($approved ? 'Proposta aprovada' : 'Proposta reprovada')." - RNC {$rnc->formatted_number}")
            ->view('emails.rnc-corrective-action-reviewed', [
                'rnc' => $rnc,
                'acaoCorretiva' => $this->acaoCorretiva,
                'actor' => $this->actor,
                'notifiable' => $notifiable,
                'approved' => $approved,
                'observacao' => $observacao,
                'url' => $actionUrl,
                'systemUrl' => $systemUrl,
            ])
            ->text('emails.rnc-corrective-action-reviewed-text', [
                'rnc' => $rnc,
                'acaoCorretiva' => $this->acaoCorretiva,
                'actor' => $this->actor,
                'notifiable' => $notifiable,
                'approved' => $approved,
                'observacao' => $observacao,
                'url' => $actionUrl,
                'systemUrl' => $systemUrl,
            ]);
    }
}
