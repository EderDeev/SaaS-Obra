<?php

namespace Tests\Feature;

use App\Models\OrdemServico;
use App\Models\OrdemServicoObraResponsavel;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\OrdemServicoReturnedForCorrectionNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class TenantOrdemServicoRejectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_fiscal_can_return_order_from_analysis_to_draft_and_notify_flow_responsibles(): void
    {
        [$tenant, $ordem, $submitter, $fiscal, $otherFiscal, $approver] = $this->flowContext('em_analise');
        Notification::fake();

        $this->actingAs($fiscal)
            ->patch(route('tenant.ordem-servico.os.reject', [$tenant, $ordem]), [
                'motivo' => 'Corrigir os quantitativos antes de reenviar.',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $ordem->refresh();

        $this->assertSame('rascunho', $ordem->status);
        $this->assertNull($ordem->submitted_for_review_at);
        $this->assertNull($ordem->submitted_for_review_by_id);
        $this->assertNull($ordem->analyzed_at);
        $this->assertNull($ordem->approval_decided_at);
        $this->assertDatabaseHas('ordem_servico_analises', [
            'ordem_servico_id' => $ordem->id,
            'user_id' => $fiscal->id,
            'tipo' => 'analise',
            'decisao' => 'reprovada',
            'observacao' => 'Corrigir os quantitativos antes de reenviar.',
        ]);

        Notification::assertSentTo($submitter, OrdemServicoReturnedForCorrectionNotification::class);
        Notification::assertSentTo($fiscal, OrdemServicoReturnedForCorrectionNotification::class);
        Notification::assertSentTo($otherFiscal, OrdemServicoReturnedForCorrectionNotification::class);
        Notification::assertSentTo($approver, OrdemServicoReturnedForCorrectionNotification::class);
    }

    public function test_approver_can_return_order_from_approval_to_draft(): void
    {
        [$tenant, $ordem, $submitter, $fiscal, $otherFiscal, $approver] = $this->flowContext('em_aprovacao');
        $ordem->forceFill([
            'analyzed_at' => now(),
            'analyzed_by_id' => $fiscal->id,
            'analysis_observation' => 'Análise concluída.',
        ])->save();
        Notification::fake();

        $this->actingAs($approver)
            ->patch(route('tenant.ordem-servico.os.reject', [$tenant, $ordem]), [
                'motivo' => 'Revisar o escopo aprovado.',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $ordem->refresh();

        $this->assertSame('rascunho', $ordem->status);
        $this->assertNull($ordem->analyzed_at);
        $this->assertNull($ordem->analyzed_by_id);
        $this->assertNull($ordem->analysis_observation);
        $this->assertDatabaseHas('ordem_servico_analises', [
            'ordem_servico_id' => $ordem->id,
            'user_id' => $approver->id,
            'tipo' => 'aprovacao',
            'decisao' => 'reprovada',
            'observacao' => 'Revisar o escopo aprovado.',
        ]);

        Notification::assertSentTo($submitter, OrdemServicoReturnedForCorrectionNotification::class);
        Notification::assertSentTo($fiscal, OrdemServicoReturnedForCorrectionNotification::class);
        Notification::assertSentTo($otherFiscal, OrdemServicoReturnedForCorrectionNotification::class);
        Notification::assertSentTo($approver, OrdemServicoReturnedForCorrectionNotification::class);
    }

    /**
     * @return array{Tenant, OrdemServico, User, User, User, User}
     */
    private function flowContext(string $status): array
    {
        $tenant = Tenant::create([
            'slug' => 'empresa-os-reprovacao',
            'name' => 'Empresa OS',
            'plan' => 'starter',
            'status' => 'active',
        ]);
        $submitter = User::factory()->create();
        $fiscal = User::factory()->create();
        $otherFiscal = User::factory()->create();
        $approver = User::factory()->create();

        foreach ([$submitter, $fiscal, $otherFiscal, $approver] as $user) {
            $tenant->memberships()->create([
                'user_id' => $user->id,
                'role' => 'tenant_member',
                'status' => 'active',
            ]);
        }

        $contract = $tenant->contracts()->create([
            'code' => 'CT-001',
            'name' => 'Contrato OS',
            'status' => 'active',
        ]);
        $obra = $contract->obras()->create([
            'tenant_id' => $tenant->id,
            'codigo' => '001',
            'nome' => 'Obra OS',
            'tipo' => 'pai',
        ]);

        foreach ([$fiscal, $otherFiscal] as $user) {
            OrdemServicoObraResponsavel::create([
                'tenant_id' => $tenant->id,
                'contract_id' => $contract->id,
                'obra_id' => $obra->id,
                'user_id' => $user->id,
                'created_by_id' => $submitter->id,
                'tipo' => 'fiscal',
                'status' => 'active',
            ]);
        }

        OrdemServicoObraResponsavel::create([
            'tenant_id' => $tenant->id,
            'contract_id' => $contract->id,
            'obra_id' => $obra->id,
            'user_id' => $approver->id,
            'created_by_id' => $submitter->id,
            'tipo' => 'aprovador',
            'status' => 'active',
        ]);

        $ordem = OrdemServico::create([
            'tenant_id' => $tenant->id,
            'contract_id' => $contract->id,
            'obra_id' => $obra->id,
            'created_by_id' => $submitter->id,
            'codigo' => 'CT001-001-OS-001',
            'sequencial' => 1,
            'titulo' => 'Executar serviço',
            'custo_previsto' => 1000,
            'status' => $status,
            'submitted_for_review_at' => now(),
            'submitted_for_review_by_id' => $submitter->id,
        ]);

        return [$tenant, $ordem, $submitter, $fiscal, $otherFiscal, $approver];
    }
}
