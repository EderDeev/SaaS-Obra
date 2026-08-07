<?php

namespace App\Notifications;

use App\Models\ProjectReviewMarkup;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

class ProjectReviewMarkupCreatedNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly ProjectReviewMarkup $markup,
        private readonly User $actor,
    ) {
        $this->markup->loadMissing(['tenant', 'contract', 'document', 'version', 'creator']);
    }

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'tenant_id' => $this->markup->tenant_id,
            'contract_id' => $this->markup->contract_id,
            'project_document_id' => $this->markup->project_document_id,
            'project_document_version_id' => $this->markup->project_document_version_id,
            'project_review_markup_id' => $this->markup->id,
            'title' => 'Novo comentário visual de projeto',
            'body' => "{$this->actor->name} criou o comentário \"{$this->markup->title}\" para você.",
            'contract' => $this->markup->contract?->code,
            'url' => route('tenant.projects.viewer', [$this->markup->tenant, $this->markup->version], false),
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $viewerUrl = route('tenant.projects.viewer', [$this->markup->tenant, $this->markup->version]);
        $systemUrl = route('tenant.dashboard', $this->markup->tenant);
        $description = Str::limit(trim(strip_tags((string) $this->markup->description)), 650);

        $viewData = [
            'headline' => 'Novo comentário visual de projeto',
            'intro' => "{$this->actor->name} registrou um comentário visual e atribuiu o acompanhamento a você.",
            'tone' => $this->markup->priority === 'critica' ? 'danger' : ($this->markup->priority === 'alta' ? 'warning' : 'info'),
            'notifiable' => $notifiable,
            'details' => [
                ['label' => 'EAP', 'value' => $this->markup->version?->eap ?: 'Sem EAP'],
                ['label' => 'Projeto', 'value' => $this->markup->document?->title ?: 'Não informado'],
                ['label' => 'Comentário', 'value' => $this->markup->title],
                ['label' => 'Contrato', 'value' => trim(($this->markup->contract?->code ? $this->markup->contract->code.' - ' : '').($this->markup->contract?->name ?? '')) ?: 'Não informado'],
                ['label' => 'Revisão', 'value' => $this->markup->version?->revision ?: 'Não informada'],
                ['label' => 'Prioridade', 'value' => $this->priorityLabel($this->markup->priority)],
                ['label' => 'Prazo', 'value' => $this->markup->due_date?->format('d/m/Y') ?: 'Sem prazo'],
                ['label' => 'Criado por', 'value' => $this->actor->name],
            ],
            'highlightTitle' => 'Descrição',
            'highlightBody' => $description ?: 'Sem descrição informada.',
            'actionLabel' => 'Abrir comentário',
            'url' => $viewerUrl,
            'systemUrl' => $systemUrl,
        ];

        return (new MailMessage)
            ->subject("Novo comentário visual de projeto: {$this->markup->title}")
            ->view('emails.project-event', $viewData)
            ->text('emails.project-event-text', $viewData);
    }

    private function priorityLabel(string $priority): string
    {
        return match ($priority) {
            'baixa' => 'Baixa',
            'normal' => 'Normal',
            'alta' => 'Alta',
            'critica' => 'Crítica',
            default => $priority,
        };
    }
}
