<?php

namespace App\Notifications;

use App\Models\ProjectDocument;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ProjectSubmittedForReviewNotification extends Notification
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
        return [
            'tenant_id' => $this->document->tenant_id,
            'contract_id' => $this->document->contract_id,
            'project_document_id' => $this->document->id,
            'title' => 'Projeto aguardando analise',
            'body' => "{$this->actor->name} submeteu o projeto \"{$this->document->title}\" para analise.",
            'contract' => $this->document->contract?->code,
            'url' => route('tenant.projects.review.index', $this->document->tenant, false),
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $reviewUrl = route('tenant.projects.review.index', $this->document->tenant);
        $systemUrl = route('tenant.dashboard', $this->document->tenant);

        $viewData = [
            'headline' => 'Projeto aguardando análise',
            'intro' => "{$this->actor->name} submeteu um projeto da sua disciplina para análise técnica.",
            'tone' => 'info',
            'notifiable' => $notifiable,
            'details' => $this->projectDetails(),
            'highlightTitle' => 'Próxima etapa',
            'highlightBody' => 'Revise o arquivo, preencha o checklist e registre seu parecer técnico.',
            'actionLabel' => 'Analisar projeto',
            'url' => $reviewUrl,
            'systemUrl' => $systemUrl,
        ];

        return (new MailMessage)
            ->subject("Projeto aguardando análise: {$this->document->title}")
            ->view('emails.project-event', $viewData)
            ->text('emails.project-event-text', $viewData);
    }

    private function projectDetails(): array
    {
        return [
            ['label' => 'EAP', 'value' => $this->document->eap($this->document->latestVersion?->revision) ?: 'Sem EAP'],
            ['label' => 'Projeto', 'value' => $this->document->title],
            ['label' => 'Contrato', 'value' => trim(($this->document->contract?->code ? $this->document->contract->code.' - ' : '').($this->document->contract?->name ?? '')) ?: 'Não informado'],
            ['label' => 'Obra', 'value' => trim(($this->document->obra?->codigo ? $this->document->obra->codigo.' - ' : '').($this->document->obra?->nome ?? '')) ?: 'Não informada'],
            ['label' => 'Disciplina', 'value' => trim(($this->document->disciplina?->sigla ? $this->document->disciplina->sigla.' - ' : '').($this->document->disciplina?->nome ?? '')) ?: 'Não informada'],
            ['label' => 'Revisão', 'value' => $this->document->latestVersion?->revision ?: 'Não informada'],
            ['label' => 'Submetido por', 'value' => $this->actor->name],
        ];
    }
}
