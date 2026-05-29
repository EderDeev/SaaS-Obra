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

        return (new MailMessage)
            ->subject("Novo comentário visual de projeto: {$this->markup->title}")
            ->view('emails.project-review-markup-created', [
                'markup' => $this->markup,
                'actor' => $this->actor,
                'notifiable' => $notifiable,
                'description' => $description,
                'priorityLabel' => $this->priorityLabel($this->markup->priority),
                'url' => $viewerUrl,
                'systemUrl' => $systemUrl,
            ])
            ->text('emails.project-review-markup-created-text', [
                'markup' => $this->markup,
                'actor' => $this->actor,
                'notifiable' => $notifiable,
                'description' => $description,
                'priorityLabel' => $this->priorityLabel($this->markup->priority),
                'url' => $viewerUrl,
                'systemUrl' => $systemUrl,
            ]);
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
