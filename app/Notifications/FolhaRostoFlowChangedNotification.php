<?php

namespace App\Notifications;

use App\Models\FolhaRosto;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class FolhaRostoFlowChangedNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly FolhaRosto $folhaRosto,
        private readonly User $actor,
        private readonly string $acao,
        private readonly string $destino,
        private readonly ?string $motivo = null,
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
            'title' => $this->title(),
            'body' => $this->body(),
            'contract' => $this->folhaRosto->contract?->code,
            'url' => $this->url(false),
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject($this->title().": {$this->folhaRosto->codigo}")
            ->greeting("Olá, {$notifiable->name}.")
            ->line($this->body())
            ->line("OS: {$this->folhaRosto->ordemServico?->codigo} - {$this->folhaRosto->ordemServico?->titulo}")
            ->line("Contrato: {$this->folhaRosto->contract?->code} - {$this->folhaRosto->contract?->name}")
            ->line("Obra: ".($this->folhaRosto->obra?->nome ?? 'Não informada'));

        if ($this->motivo) {
            $mail->line("Motivo: {$this->motivo}");
        }

        return $mail
            ->action($this->acao === 'retornar_construtora' ? 'Acessar Folha de Rosto' : 'Acessar análise do pleito', $this->url())
            ->line('Acesse o sistema para acompanhar a movimentação.');
    }

    private function title(): string
    {
        return match ($this->acao) {
            'retornar_construtora' => 'FR retornada para construtora',
            'finalizar' => 'FR analisada',
            default => 'FR encaminhada',
        };
    }

    private function body(): string
    {
        return match ($this->acao) {
            'retornar_construtora' => "{$this->actor->name} retornou a FR {$this->folhaRosto->codigo} para a construtora.",
            'finalizar' => "{$this->actor->name} finalizou a análise da FR {$this->folhaRosto->codigo}.",
            default => "{$this->actor->name} encaminhou a FR {$this->folhaRosto->codigo} para {$this->destinoLabel()}.",
        };
    }

    private function destinoLabel(): string
    {
        return match ($this->destino) {
            'qualidade' => 'a qualidade',
            'medicao' => 'a medição',
            'construtora' => 'a construtora',
            'analisada' => 'finalização',
            default => 'a próxima etapa',
        };
    }

    private function url(bool $absolute = true): string
    {
        if ($this->acao === 'retornar_construtora' && $this->folhaRosto->ordemServico) {
            return route('tenant.medicao.folha-rosto.show', [
                $this->folhaRosto->tenant,
                $this->folhaRosto->ordemServico,
                'boletim_id' => $this->folhaRosto->boletim_medicao_id,
            ], $absolute);
        }

        return route('tenant.medicao.analisar-pleito.index', $this->folhaRosto->tenant, $absolute);
    }
}
