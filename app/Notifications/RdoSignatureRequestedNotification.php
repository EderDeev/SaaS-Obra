<?php

namespace App\Notifications;

use App\Models\RdoSignatureRequest;
use App\Models\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class RdoSignatureRequestedNotification extends Notification
{
    use Queueable;

    public function __construct(
        public RdoSignatureRequest $signatureRequest,
        public Tenant $tenant,
        public ?string $signingUrl = null,
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toArray(object $notifiable): array
    {
        $rdo = $this->signatureRequest->rdo;

        return [
            'type' => 'rdo_signature_requested',
            'title' => "Assinatura do RDO {$rdo->code}",
            'body' => 'Você foi indicado como responsável pela assinatura deste RDO.',
            'tenant_id' => $this->tenant->id,
            'rdo_id' => $rdo->id,
            'signature_request_id' => $this->signatureRequest->id,
            'url' => route('tenant.diario-obra.rdo.show', [$this->tenant->slug, $rdo->id]),
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $rdo = $this->signatureRequest->rdo;
        $rdo->loadMissing('contract');
        $rdoUrl = route('tenant.diario-obra.rdo.show', [$this->tenant->slug, $rdo->id]);

        return (new MailMessage)
            ->subject("RDO {$rdo->code} - assinatura solicitada")
            ->view('emails.rdo-signature-requested', [
                'notifiable' => $notifiable,
                'rdo' => $rdo,
                'signingUrl' => $this->signingUrl,
                'rdoUrl' => $rdoUrl,
            ])
            ->text('emails.rdo-signature-requested-text', [
                'notifiable' => $notifiable,
                'rdo' => $rdo,
                'signingUrl' => $this->signingUrl,
                'rdoUrl' => $rdoUrl,
            ]);
    }
}
