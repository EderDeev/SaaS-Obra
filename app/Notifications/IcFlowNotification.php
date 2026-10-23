<?php

namespace App\Notifications;

use App\Models\InstrucaoCampo;
use App\Models\User;
use App\Support\IcPermissions;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

class IcFlowNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public string $instruction;

    public ?string $comment;

    public function backoff(): array
    {
        return [10, 30, 60];
    }

    public function __construct(public InstrucaoCampo $ic, public User $actor, public string $event)
    {
        $this->instruction = $ic->instrucao;
        $this->comment = $ic->events()->latest('id')->value('comment');
        $this->onConnection('database')->onQueue('default')->beforeCommit();
    }

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function shouldSend(object $notifiable, string $channel): bool
    {
        return ! $this->ic->trashed() && IcPermissions::can($notifiable, $this->ic->tenant, IcPermissions::VIEW, $this->ic->contract);
    }

    private function title(): string
    {
        return match ($this->event) {
            'notified' => 'IC comunicada: verificar instrução',
            'correction_submitted' => 'IC: correção aguardando verificação',
            'returned' => 'IC devolvida para nova correção',
            'closed', 'finalized' => 'IC finalizada',
            'rnc_required' => 'IC: necessidade de abertura de RNC',
            default => 'IC atualizada',
        };
    }

    public function toArray(object $notifiable): array
    {
        return ['tenant_id' => $this->ic->tenant_id, 'contract_id' => $this->ic->contract_id, 'ic_id' => $this->ic->id,
            'title' => $this->title(), 'body' => "{$this->actor->name} atualizou a IC {$this->ic->formatted_number}.".($this->event === 'finalized' ? ' Problema resolvido: '.(InstrucaoCampo::RESOLUTION_RESULTS[$this->ic->resolution_result] ?? '—').'.' : ''),
            'url' => route('tenant.qualidade.ic.show', [$this->ic->tenant, $this->ic], false)];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $this->ic->loadMissing(['tenant', 'contract', 'obra']);

        return (new MailMessage)->subject($this->title().' · '.$this->ic->formatted_number)
            ->greeting('Olá, '.$notifiable->name.'.')->line($this->title().'.')
            ->line('IC '.$this->ic->formatted_number.' · Contrato '.$this->ic->contract->code.' · '.$this->ic->obra->nome)
            ->line($this->event === 'finalized' ? 'O problema foi resolvido? '.(InstrucaoCampo::RESOLUTION_RESULTS[$this->ic->resolution_result] ?? '—') : 'Consulte a instrução e registre as evidências no sistema.')
            ->line(Str::limit($this->instruction, 600))->line($this->comment ?: 'Consulte a instrução e os registros da correção no sistema.')
            ->action('Acessar IC', route('tenant.qualidade.ic.show', [$this->ic->tenant, $this->ic]));
    }
}
