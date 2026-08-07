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

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

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
        $stageLabel = $this->stage === 'approval' ? 'Aprovação final' : 'Análise técnica';

        $viewData = [
            'headline' => 'Projeto devolvido para correção',
            'intro' => "{$this->actor->name} reprovou o projeto durante a etapa de ".mb_strtolower($stageLabel).'.',
            'tone' => 'danger',
            'notifiable' => $notifiable,
            'details' => [
                ['label' => 'EAP', 'value' => $this->document->eap($this->document->latestVersion?->revision) ?: 'Sem EAP'],
                ['label' => 'Projeto', 'value' => $this->document->title],
                ['label' => 'Contrato', 'value' => trim(($this->document->contract?->code ? $this->document->contract->code.' - ' : '').($this->document->contract?->name ?? '')) ?: 'Não informado'],
                ['label' => 'Revisão', 'value' => $this->document->latestVersion?->revision ?: 'Não informada'],
                ['label' => 'Etapa', 'value' => $stageLabel],
                ['label' => 'Decisão registrada por', 'value' => $this->actor->name],
            ],
            'highlightTitle' => 'Motivo da reprovação',
            'highlightBody' => $this->reason,
            'reason' => $this->reason,
            'stageLabel' => $stageLabel,
            'actionLabel' => 'Corrigir projeto',
            'url' => $projectUrl,
            'systemUrl' => $systemUrl,
        ];

        return (new MailMessage)
            ->subject("Projeto reprovado: {$this->document->title}")
            ->view('emails.project-event', $viewData)
            ->text('emails.project-event-text', $viewData);
    }
}
