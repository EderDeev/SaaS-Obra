<?php

namespace App\Notifications;

use App\Models\OsDnitOrder;
use App\Models\OsDnitOrderEvent;
use App\Models\Tenant;
use App\Models\User;
use App\Services\OsDnitFlowRecipients;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class OsDnitFlowChangedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 60;

    public function __construct(public int $eventId)
    {
        $this->onConnection('os_dnit_mail')->onQueue('os-dnit-mail')->beforeCommit();
    }

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function viaConnections(): array
    {
        // Store the bell alert inside the workflow transaction; email retries stay independent.
        return ['database' => 'sync', 'mail' => 'os_dnit_mail'];
    }

    public function backoff(): array
    {
        return [60, 300, 900, 1800];
    }

    public function shouldSend(object $notifiable, string $channel): bool
    {
        $event = OsDnitOrderEvent::find($this->eventId);

        return in_array($channel, ['database', 'mail'], true) && $notifiable instanceof User && $event
            && app(OsDnitFlowRecipients::class)->forEvent($event)->contains('id', $notifiable->id);
    }

    public function toDatabase(object $notifiable): array
    {
        $event = OsDnitOrderEvent::with(['order.contract', 'actor'])->findOrFail($this->eventId);
        $order = $event->order;
        $tenant = Tenant::findOrFail($order->tenant_id);

        return [
            'tenant_id' => $tenant->id, 'contract_id' => $order->contract_id,
            'os_dnit_order_id' => $order->id, 'os_dnit_event_id' => $event->id,
            'title' => "OS {$event->snapshot['request_number']} · {$this->headline($event)}",
            'body' => ($event->actor?->name ?? 'Responsável').': '.$this->nextStep($event),
            'contract' => $order->contract->code,
            'url' => route('tenant.os-dnit.orders.show', [$tenant->slug, $order->id], false),
            'action' => $event->action, 'status' => $event->to_status,
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $event = OsDnitOrderEvent::with(['order.contract', 'actor'])->findOrFail($this->eventId);
        $order = $event->order;
        $tenant = Tenant::findOrFail($order->tenant_id);
        $headline = $this->headline($event);
        $nextStep = $this->nextStep($event);
        $viewData = [
            'notifiable' => $notifiable, 'event' => $event, 'snapshot' => $event->snapshot,
            'contract' => $order->contract, 'headline' => $headline, 'nextStep' => $nextStep,
            'statusLabel' => OsDnitOrder::STATUSES[$event->to_status] ?? $event->to_status,
            'url' => route('tenant.os-dnit.orders.show', [$tenant->slug, $order->id]),
            'occurredAt' => $event->created_at->timezone(config('app.timezone'))->format('d/m/Y H:i'),
        ];

        return (new MailMessage)->subject("Ordem de Serviço {$event->snapshot['request_number']} — {$headline}")
            ->view('emails.os-dnit-flow', $viewData)->text('emails.os-dnit-flow-text', $viewData);
    }

    private function headline(OsDnitOrderEvent $event): string
    {
        return match ($event->action) {
            'submitted' => $event->from_status === 'returned_analyst' ? 'OS reenviada para análise Supervisão' : 'OS enviada para análise Supervisão',
            'forwarded' => 'Análise encaminhada ao fiscal do contrato',
            'returned' => $event->to_status === 'returned_concremat' ? 'OS reprovada e devolvida à Supervisão' : 'OS reprovada e devolvida ao analista DNIT',
            'approved' => 'Demanda aprovada',
            'completed' => 'OS finalizada: serviço concluído',
            default => 'Atualização da OS',
        };
    }

    private function nextStep(OsDnitOrderEvent $event): string
    {
        return match ($event->to_status) {
            'awaiting_concremat' => 'A solicitação aguarda análise da Supervisão.',
            'awaiting_contract' => 'O fiscal do contrato deve consultar a demanda e a Análise Supervisão para decidir sobre a autorização.',
            'returned_concremat' => 'A Supervisão deve consultar a justificativa, revisar a análise e encaminhá-la novamente.',
            'returned_analyst' => 'O analista DNIT deve consultar a justificativa da devolução. O pedido original permanece bloqueado para edição.',
            'completed' => 'O fiscal do contrato registrou a conclusão do serviço e finalizou a OS.',
            'approved' => 'O serviço foi aprovado pelo fiscal do contrato. Consulte a OS e a análise autorizada no sistema.',
            default => 'Consulte o andamento da OS no sistema.',
        };
    }
}
