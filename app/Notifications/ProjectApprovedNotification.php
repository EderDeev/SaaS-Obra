<?php

namespace App\Notifications;

use App\Models\ProjectDocument;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ProjectApprovedNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly ProjectDocument $document,
        private readonly User $actor,
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
        $isRevision = $this->isRevision();
        $revision = $this->document->latestVersion?->revision;

        return [
            'tenant_id' => $this->document->tenant_id,
            'contract_id' => $this->document->contract_id,
            'project_document_id' => $this->document->id,
            'project_document_version_id' => $this->document->latestVersion?->id,
            'title' => $isRevision ? 'Revisao de projeto aprovada' : 'Projeto aprovado',
            'body' => $isRevision
                ? "A revisao {$revision} do projeto \"{$this->document->title}\" foi aprovada por {$this->actor->name} e esta disponivel para uso."
                : "{$this->actor->name} aprovou o projeto \"{$this->document->title}\" e liberou para a arvore principal.",
            'contract' => $this->document->contract?->code,
            'revision' => $revision,
            'cap_number' => $this->document->latestVersion?->cap_number,
            'url' => route($isRevision ? 'tenant.projects.revisions.index' : 'tenant.projects.visualizar.index', $this->document->tenant, false),
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $isRevision = $this->isRevision();
        $revision = $this->document->latestVersion?->revision;
        $projectsUrl = route($isRevision ? 'tenant.projects.revisions.index' : 'tenant.projects.visualizar.index', $this->document->tenant);
        $systemUrl = route('tenant.dashboard', $this->document->tenant);

        return (new MailMessage)
            ->subject($isRevision
                ? "Revisao de projeto aprovada: {$this->document->title} - {$revision}"
                : "Projeto aprovado: {$this->document->title}")
            ->view('emails.project-approved', [
                'document' => $this->document,
                'actor' => $this->actor,
                'notifiable' => $notifiable,
                'isRevision' => $isRevision,
                'url' => $projectsUrl,
                'systemUrl' => $systemUrl,
            ])
            ->text('emails.project-approved-text', [
                'document' => $this->document,
                'actor' => $this->actor,
                'notifiable' => $notifiable,
                'isRevision' => $isRevision,
                'url' => $projectsUrl,
                'systemUrl' => $systemUrl,
            ]);
    }

    private function isRevision(): bool
    {
        return filled($this->document->latestVersion?->cap_number);
    }
}
