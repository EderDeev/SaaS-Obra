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

        $viewData = [
            'headline' => $isRevision ? 'Revisão de projeto aprovada' : 'Projeto aprovado',
            'intro' => $isRevision
                ? "{$this->actor->name} aprovou a revisão {$revision}. A nova versão está disponível para uso."
                : "{$this->actor->name} aprovou o projeto e o liberou para a árvore principal.",
            'tone' => 'success',
            'notifiable' => $notifiable,
            'details' => $this->projectDetails($isRevision),
            'highlightTitle' => 'Situação atual',
            'highlightBody' => $isRevision
                ? 'A revisão aprovada substitui a versão anterior na visualização oficial do projeto.'
                : 'O projeto já pode ser consultado pelos usuários autorizados do contrato.',
            'actionLabel' => $isRevision ? 'Ver revisões' : 'Ver projetos',
            'url' => $projectsUrl,
            'systemUrl' => $systemUrl,
        ];

        return (new MailMessage)
            ->subject($isRevision
                ? "Revisão de projeto aprovada: {$this->document->title} - {$revision}"
                : "Projeto aprovado: {$this->document->title}")
            ->view('emails.project-event', $viewData)
            ->text('emails.project-event-text', $viewData);
    }

    private function projectDetails(bool $isRevision): array
    {
        $details = [
            ['label' => 'EAP', 'value' => $this->document->eap($this->document->latestVersion?->revision) ?: 'Sem EAP'],
            ['label' => 'Projeto', 'value' => $this->document->title],
            ['label' => 'Contrato', 'value' => trim(($this->document->contract?->code ? $this->document->contract->code.' - ' : '').($this->document->contract?->name ?? '')) ?: 'Não informado'],
            ['label' => 'Obra', 'value' => trim(($this->document->obra?->codigo ? $this->document->obra->codigo.' - ' : '').($this->document->obra?->nome ?? '')) ?: 'Não informada'],
            ['label' => 'Disciplina', 'value' => trim(($this->document->disciplina?->sigla ? $this->document->disciplina->sigla.' - ' : '').($this->document->disciplina?->nome ?? '')) ?: 'Não informada'],
            ['label' => 'Revisão', 'value' => $this->document->latestVersion?->revision ?: 'Não informada'],
        ];

        if ($isRevision) {
            $details[] = ['label' => 'CAP', 'value' => $this->document->latestVersion?->cap_number ?: 'Não informada'];
        }

        $details[] = ['label' => 'Aprovado por', 'value' => $this->actor->name];

        return $details;
    }

    private function isRevision(): bool
    {
        return filled($this->document->latestVersion?->cap_number);
    }
}
