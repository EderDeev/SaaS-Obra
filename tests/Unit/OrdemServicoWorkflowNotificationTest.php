<?php

namespace Tests\Unit;

use App\Models\Contract;
use App\Models\Obra;
use App\Models\OrdemServico;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\OrdemServicoReadyForApprovalNotification;
use App\Notifications\OrdemServicoReturnedForCorrectionNotification;
use App\Notifications\OrdemServicoSubmittedForReviewNotification;
use Tests\TestCase;

class OrdemServicoWorkflowNotificationTest extends TestCase
{
    public function test_review_request_uses_deming_mail_layout(): void
    {
        [$ordem, $actor, $recipient] = $this->notificationContext();

        $mail = (new OrdemServicoSubmittedForReviewNotification($ordem, $actor))->toMail($recipient);

        $this->assertSame('OS aguardando análise: OS-0050', $mail->subject);
        $this->assertSame('emails.ordem-servico-flow', data_get($mail->view, 'html'));
        $this->assertSame('emails.ordem-servico-flow-text', data_get($mail->view, 'text'));
        $this->assertSame('Em análise', data_get($mail->viewData, 'statusLabel'));
        $this->assertSame('Analisar OS', data_get($mail->viewData, 'actionLabel'));
        $this->assertStringContainsString('/ordem-servico/analise', data_get($mail->viewData, 'url'));
        $this->assertStringContainsString(
            'Deming · Ordem de Serviço',
            app('mailer')->render($mail->view, $mail->viewData)
        );
    }

    public function test_approval_request_uses_deming_mail_layout(): void
    {
        [$ordem, $actor, $recipient] = $this->notificationContext();
        $ordem->analysis_observation = 'Escopo conferido pelo fiscal.';

        $mail = (new OrdemServicoReadyForApprovalNotification($ordem, $actor))->toMail($recipient);

        $this->assertSame('OS aguardando aprovação: OS-0050', $mail->subject);
        $this->assertSame('emails.ordem-servico-flow', data_get($mail->view, 'html'));
        $this->assertSame('emails.ordem-servico-flow-text', data_get($mail->view, 'text'));
        $this->assertSame('Em aprovação', data_get($mail->viewData, 'statusLabel'));
        $this->assertSame('Avaliar aprovação', data_get($mail->viewData, 'actionLabel'));
        $this->assertSame('Escopo conferido pelo fiscal.', data_get($mail->viewData, 'observation'));
        $this->assertStringContainsString(
            'Decisão necessária',
            app('mailer')->render($mail->view, $mail->viewData)
        );
    }

    public function test_rejection_uses_deming_mail_layout_and_correction_message(): void
    {
        [$ordem, $actor, $recipient] = $this->notificationContext();

        $mail = (new OrdemServicoReturnedForCorrectionNotification(
            $ordem,
            $actor,
            'análise',
            'Corrigir o quantitativo do item 1.2.'
        ))->toMail($recipient);

        $this->assertSame('OS devolvida para correção: OS-0050', $mail->subject);
        $this->assertSame('emails.ordem-servico-flow', data_get($mail->view, 'html'));
        $this->assertSame('Rascunho', data_get($mail->viewData, 'statusLabel'));
        $this->assertSame('danger', data_get($mail->viewData, 'tone'));
        $this->assertSame(
            'Corrigir o quantitativo do item 1.2.',
            data_get($mail->viewData, 'observation')
        );
        $this->assertStringContainsString(
            'OS devolvida para correção',
            app('mailer')->render($mail->view, $mail->viewData)
        );
    }

    /**
     * @return array{OrdemServico, User, User}
     */
    private function notificationContext(): array
    {
        $tenant = (new Tenant)->forceFill([
            'id' => 1,
            'slug' => 'empresa-teste',
            'name' => 'Empresa Teste',
        ]);
        $contract = (new Contract)->forceFill([
            'id' => 10,
            'code' => 'CT-001',
            'name' => 'Contrato Teste',
        ]);
        $obra = (new Obra)->forceFill([
            'id' => 20,
            'codigo' => '001',
            'nome' => 'Obra Teste',
        ]);
        $actor = (new User)->forceFill([
            'id' => 30,
            'name' => 'Admin Plataforma',
            'email' => 'admin@example.com',
        ]);
        $recipient = (new User)->forceFill([
            'id' => 40,
            'name' => 'Responsável OS',
            'email' => 'responsavel@example.com',
        ]);
        $ordem = (new OrdemServico)->forceFill([
            'id' => 50,
            'tenant_id' => $tenant->id,
            'contract_id' => $contract->id,
            'obra_id' => $obra->id,
            'created_by_id' => $actor->id,
            'codigo' => 'OS-0050',
            'titulo' => 'Executar serviço',
            'prazo_execucao' => '2026-08-15',
            'custo_previsto' => 125000,
        ]);

        $ordem->setRelation('tenant', $tenant);
        $ordem->setRelation('contract', $contract);
        $ordem->setRelation('obra', $obra);
        $ordem->setRelation('creator', $actor);

        return [$ordem, $actor, $recipient];
    }
}
