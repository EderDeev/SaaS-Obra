<?php

namespace App\Notifications;

use App\Models\ProjectSubmissionBatch;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ProjectBatchWorkflowNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly ProjectSubmissionBatch $batch,
        private readonly User $actor,
        private readonly string $event,
        private readonly ?string $reason = null,
    ) {
        $this->batch->loadMissing(['tenant', 'contract', 'versions.document']);
    }

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'tenant_id' => $this->batch->tenant_id,
            'contract_id' => $this->batch->contract_id,
            'project_submission_batch_id' => $this->batch->id,
            'title' => $this->title(),
            'body' => $this->body(),
            'contract' => $this->batch->contract?->code,
            'cap_number' => $this->batch->cap_number,
            'url' => route($this->event === 'rejected' ? 'tenant.projects.index' : 'tenant.projects.review.index', $this->batch->tenant, false),
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = route($this->event === 'rejected' ? 'tenant.projects.index' : 'tenant.projects.review.index', $this->batch->tenant);
        $systemUrl = route('tenant.dashboard', $this->batch->tenant);
        $config = $this->eventConfig();
        $details = [
            ['label' => 'Pacote', 'value' => $this->batch->package_number],
            ['label' => 'Título', 'value' => $this->batch->title ?: 'Sem título'],
            ['label' => 'Contrato', 'value' => trim(($this->batch->contract?->code ? $this->batch->contract->code.' - ' : '').($this->batch->contract?->name ?? '')) ?: 'Não informado'],
            ['label' => 'Projetos', 'value' => (string) $this->batch->versions->count()],
        ];

        if ($this->batch->cap_number) {
            $details[] = ['label' => 'CAP', 'value' => $this->batch->cap_number];
        }

        $details[] = ['label' => 'Ação realizada por', 'value' => $this->actor->name];

        $viewData = [
            'headline' => $this->title(),
            'intro' => $this->body(),
            'tone' => $config['tone'],
            'notifiable' => $notifiable,
            'details' => $details,
            'highlightTitle' => $this->reason ? 'Motivo da devolução' : $config['highlight_title'],
            'highlightBody' => $this->reason ?: $config['highlight_body'],
            'actionLabel' => $config['action_label'],
            'url' => $url,
            'systemUrl' => $systemUrl,
        ];

        return (new MailMessage)
            ->subject($this->title().' - '.$this->batch->package_number)
            ->view('emails.project-event', $viewData)
            ->text('emails.project-event-text', $viewData);
    }

    private function eventConfig(): array
    {
        return match ($this->event) {
            'submitted' => [
                'tone' => 'info',
                'highlight_title' => 'Próxima etapa',
                'highlight_body' => 'Analise os projetos do pacote e registre um único parecer consolidado.',
                'action_label' => 'Analisar pacote',
            ],
            'verified' => [
                'tone' => 'info',
                'highlight_title' => 'Próxima etapa',
                'highlight_body' => 'Confira o parecer do pacote e registre a decisão final.',
                'action_label' => 'Aprovar pacote',
            ],
            'approved' => [
                'tone' => 'success',
                'highlight_title' => 'Situação atual',
                'highlight_body' => 'Os projetos aprovados já estão disponíveis na árvore principal.',
                'action_label' => 'Ver projetos',
            ],
            'rejected' => [
                'tone' => 'danger',
                'highlight_title' => 'Correção necessária',
                'highlight_body' => 'Revise os arquivos indicados e submeta o pacote novamente.',
                'action_label' => 'Corrigir pacote',
            ],
            default => [
                'tone' => 'info',
                'highlight_title' => 'Atualização',
                'highlight_body' => 'O pacote recebeu uma atualização no fluxo de projetos.',
                'action_label' => 'Abrir pacote',
            ],
        };
    }

    private function title(): string
    {
        return match ($this->event) {
            'submitted' => 'Pacote de projetos aguardando análise',
            'verified' => 'Pacote de projetos aguardando aprovação',
            'approved' => 'Pacote de projetos aprovado',
            'rejected' => 'Pacote de projetos devolvido para correção',
            default => 'Atualização no pacote de projetos',
        };
    }

    private function body(): string
    {
        return match ($this->event) {
            'submitted' => "{$this->actor->name} submeteu {$this->batch->versions->count()} projetos em um único pacote para análise.",
            'verified' => "{$this->actor->name} concluiu a análise do pacote e o enviou para aprovação final.",
            'approved' => "{$this->actor->name} aprovou o pacote. Os projetos foram liberados para a árvore principal.",
            'rejected' => "{$this->actor->name} devolveu o pacote completo para correção.",
            default => "{$this->actor->name} atualizou o pacote de projetos.",
        };
    }
}
