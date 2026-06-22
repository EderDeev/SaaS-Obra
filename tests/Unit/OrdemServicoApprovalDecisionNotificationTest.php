<?php

namespace Tests\Unit;

use App\Models\Contract;
use App\Models\Obra;
use App\Models\OrdemServico;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\OrdemServicoApprovalDecisionNotification;
use Tests\TestCase;

class OrdemServicoApprovalDecisionNotificationTest extends TestCase
{
    public function test_approved_requester_receives_execution_release_message(): void
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
            'nome' => 'Obra Teste',
        ]);
        $requester = (new User)->forceFill([
            'id' => 30,
            'name' => 'Solicitante',
            'email' => 'solicitante@example.com',
        ]);
        $approver = (new User)->forceFill([
            'id' => 40,
            'name' => 'Aprovador',
            'email' => 'aprovador@example.com',
        ]);
        $ordem = (new OrdemServico)->forceFill([
            'id' => 50,
            'tenant_id' => $tenant->id,
            'contract_id' => $contract->id,
            'obra_id' => $obra->id,
            'created_by_id' => $requester->id,
            'codigo' => 'OS-0050',
            'titulo' => 'Executar serviço',
        ]);

        $ordem->setRelation('tenant', $tenant);
        $ordem->setRelation('contract', $contract);
        $ordem->setRelation('obra', $obra);
        $ordem->setRelation('creator', $requester);

        $notification = new OrdemServicoApprovalDecisionNotification(
            $ordem,
            $approver,
            'aprovada',
            'Serviço liberado.'
        );

        $mail = $notification->toMail($requester);
        $database = $notification->toArray($requester);

        $this->assertSame('OS aprovada: OS-0050', $mail->subject);
        $this->assertContains(
            'A execução do serviço está autorizada e já pode ser iniciada conforme o escopo aprovado.',
            $mail->introLines
        );
        $this->assertSame('Acessar OS liberada', $mail->actionText);
        $this->assertStringContainsString('/ordem-servico/os', $mail->actionUrl);
        $this->assertStringContainsString('A execução do serviço está autorizada.', $database['body']);
    }
}
