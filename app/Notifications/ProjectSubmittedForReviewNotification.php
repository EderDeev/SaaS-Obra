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

        return (new MailMessage)
            ->subject("Projeto aguardando analise: {$this->document->title}")
            ->view('emails.project-submitted-for-review', [
                'document' => $this->document,
                'actor' => $this->actor,
                'notifiable' => $notifiable,
                'url' => $reviewUrl,
                'systemUrl' => $systemUrl,
            ])
            ->text('emails.project-submitted-for-review-text', [
                'document' => $this->document,
                'actor' => $this->actor,
                'notifiable' => $notifiable,
                'url' => $reviewUrl,
                'systemUrl' => $systemUrl,
            ]);
    }
}
