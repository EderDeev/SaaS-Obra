<?php

namespace App\Notifications;

use App\Models\FolhaRosto;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class FolhaRostoSubmittedForAnalysisNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly FolhaRosto $folhaRosto,
        private readonly User $actor,
        private readonly string $etapa,
    ) {
        $this->folhaRosto->loadMissing(['tenant', 'contract', 'obra', 'ordemServico', 'boletimMedicao']);
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
            'tenant_id' => $this->folhaRosto->tenant_id,
            'contract_id' => $this->folhaRosto->contract_id,
            'folha_rosto_id' => $this->folhaRosto->id,
            'title' => 'FR aguardando análise',
            'body' => "{$this->actor->name} enviou a FR {$this->folhaRosto->codigo} para análise {$this->etapaLabel()}.",
            'contract' => $this->folhaRosto->contract?->code,
            'url' => route('tenant.medicao.analisar-pleito.index', $this->folhaRosto->tenant, false),
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("FR aguardando análise: {$this->folhaRosto->codigo}")
            ->greeting("Olá, {$notifiable->name}.")
            ->line("A Folha de Rosto {$this->folhaRosto->codigo} foi enviada para análise {$this->etapaLabel()}.")
            ->line("OS: {$this->folhaRosto->ordemServico?->codigo} - {$this->folhaRosto->ordemServico?->titulo}")
            ->line("Contrato: {$this->folhaRosto->contract?->code} - {$this->folhaRosto->contract?->name}")
            ->line("Obra: ".($this->folhaRosto->obra?->nome ?? 'Não informada'))
            ->action('Acessar análise do pleito', route('tenant.medicao.analisar-pleito.index', $this->folhaRosto->tenant))
            ->line('Acesse o sistema para verificar os pleitos pendentes.');
    }

    private function etapaLabel(): string
    {
        return match ($this->etapa) {
            'qualidade' => 'da qualidade',
            'medicao' => 'da medição',
            default => 'do fiscal',
        };
    }
}
