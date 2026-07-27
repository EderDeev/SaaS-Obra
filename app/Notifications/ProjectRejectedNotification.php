<?php

namespace App\Notifications;

use App\Models\ProjectDocument;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ProjectRejectedNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly ProjectDocument $document,
        private readonly User $actor,
        private readonly string $reason,
        private readonly string $stage,
    ) {
        $this->document->loadMissing(['tenant', 'contract', 'obra', 'disciplina', 'latestVersion']);
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
            'tenant_id' => $this->document->tenant_id,
            'contract_id' => $this->document->contract_id,
            'project_document_id' => $this->document->id,
            'title' => 'Projeto reprovado',
            'body' => "{$this->actor->name} reprovou o projeto \"{$this->document->title}\". Motivo: {$this->reason}",
            'contract' => $this->document->contract?->code,
            'url' => route('tenant.projects.index', $this->document->tenant, false),
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $projectUrl = route('tenant.projects.index', $this->document->tenant);
        $systemUrl = route('tenant.dashboard', $this->document->tenant);

        return (new MailMessage)
            ->subject("Projeto reprovado: {$this->document->title}")
            ->view('emails.project-rejected', [
                'document' => $this->document,
                'actor' => $this->actor,
                'notifiable' => $notifiable,
                'reason' => $this->reason,
                'stageLabel' => $this->stage === 'approval' ? 'Aprovação final' : 'Análise técnica',
                'url' => $projectUrl,
                'systemUrl' => $systemUrl,
            ])
            ->text('emails.project-rejected-text', [
                'document' => $this->document,
                'actor' => $this->actor,
                'notifiable' => $notifiable,
                'reason' => $this->reason,
                'stageLabel' => $this->stage === 'approval' ? 'Aprovação final' : 'Análise técnica',
                'url' => $projectUrl,
                'systemUrl' => $systemUrl,
            ]);
    }
}
