<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\MedicaoItem;
use App\Models\OrdemServico;
use App\Models\OrdemServicoContractSetting;
use App\Models\OrdemServicoObraResponsavel;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\OrdemServicoCommentNotification;
use App\Notifications\OrdemServicoLifecycleNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TenantOrdemServicoExecutionFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_contract_requirements_block_submission_until_order_is_complete(): void
    {
        [$tenant, $owner, $contract, $ordem] = $this->context('rascunho');

        OrdemServicoContractSetting::create([
            'tenant_id' => $tenant->id,
            'contract_id' => $contract->id,
            'require_document' => true,
            'require_deadline' => true,
            'require_execution_responsible' => true,
            'created_by_id' => $owner->id,
            'updated_by_id' => $owner->id,
        ]);

        $this->actingAs($owner)
            ->patch(route('tenant.ordem-servico.os.submit-analysis', [$tenant, $ordem]))
            ->assertSessionHasErrors(['documentos', 'prazo_inicio', 'prazo_finalizacao', 'responsavel_ids']);

        $ordem->update([
            'prazo_inicio' => now()->addDay(),
            'prazo_finalizacao' => now()->addDays(10),
            'prazo_execucao' => now()->addDays(10),
        ]);
        $ordem->responsaveis()->create(['user_id' => $owner->id, 'papel' => 'execucao']);
        $ordem->documentos()->create([
            'uploaded_by_id' => $owner->id,
            'categoria' => 'execucao',
            'nome_original' => 'escopo.pdf',
            'path' => 'ordens/escopo.pdf',
            'mime_type' => 'application/pdf',
            'size' => 100,
        ]);

        $this->actingAs($owner)
            ->patch(route('tenant.ordem-servico.os.submit-analysis', [$tenant, $ordem]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame('em_analise', $ordem->refresh()->status);
    }

    public function test_order_requirements_are_managed_on_the_settings_page(): void
    {
        [$tenant, $owner, $contract] = $this->context('rascunho');

        $this->actingAs($owner)
            ->get(route('tenant.ordem-servico.settings.index', [$tenant, 'contract_id' => $contract->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Tenant/OrdemServico/Settings')
                ->where('selectedContractId', $contract->id)
                ->where('requirements.require_project', false)
                ->where('requirements.require_document', false)
            );

        $this->actingAs($owner)
            ->patch(route('tenant.ordem-servico.settings.update', $tenant), [
                'contract_id' => $contract->id,
                'require_project' => true,
                'require_document' => true,
                'require_deadline' => false,
                'require_execution_responsible' => true,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('ordem_servico_contract_settings', [
            'tenant_id' => $tenant->id,
            'contract_id' => $contract->id,
            'require_project' => true,
            'require_document' => true,
            'require_deadline' => false,
            'require_execution_responsible' => true,
        ]);
    }

    public function test_approved_order_can_be_executed_completed_with_evidence_and_exported(): void
    {
        Storage::fake('public');
        Notification::fake();
        [$tenant, $owner, $contract, $ordem, $participant] = $this->context('aprovada', true);
        $ordem->responsaveis()->create(['user_id' => $participant->id, 'papel' => 'execucao']);

        $this->actingAs($owner)
            ->patch(route('tenant.ordem-servico.os.start-execution', [$tenant, $ordem]))
            ->assertRedirect();

        $this->assertSame('em_execucao', $ordem->refresh()->status);

        $this->actingAs($owner)
            ->post(route('tenant.ordem-servico.os.complete', [$tenant, $ordem]), [
                'completion_summary' => 'Serviço executado e conferido em campo.',
                'evidencias' => [UploadedFile::fake()->image('evidencia.jpg')],
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $ordem->refresh();
        $this->assertSame('concluida', $ordem->status);
        $this->assertSame('Serviço executado e conferido em campo.', $ordem->completion_summary);
        $this->assertDatabaseHas('ordem_servico_documentos', [
            'ordem_servico_id' => $ordem->id,
            'categoria' => 'conclusao',
            'nome_original' => 'evidencia.jpg',
        ]);
        Notification::assertSentTo($participant, OrdemServicoLifecycleNotification::class);

        $this->actingAs($owner)
            ->get(route('tenant.ordem-servico.os.pdf', [$tenant, $ordem]))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    public function test_order_has_a_dedicated_detail_page_with_actions_and_sections_data(): void
    {
        [$tenant, $owner, $contract, $ordem] = $this->context('aprovada');
        $ordem->responsaveis()->create(['user_id' => $owner->id, 'papel' => 'execucao']);

        $this->actingAs($owner)
            ->get(route('tenant.ordem-servico.os.show', [$tenant, $ordem]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Tenant/OrdemServico/Show')
                ->where('ordem.id', $ordem->id)
                ->where('ordem.codigo', $ordem->codigo)
                ->where('ordem.status', 'aprovada')
                ->has('ordem.responsaveis', 1)
                ->where('can.execute', true)
                ->where('can.complete', true)
            );
    }

    public function test_order_detail_paginates_items_without_losing_global_totals(): void
    {
        [$tenant, $owner, $contract, $ordem] = $this->context('aprovada');

        for ($sequence = 2; $sequence <= 55; $sequence++) {
            $item = MedicaoItem::create([
                'tenant_id' => $tenant->id,
                'contract_id' => $contract->id,
                'created_by_id' => $owner->id,
                'item' => '1.'.$sequence,
                'item_type' => 'manual',
                'codigo' => 'ITEM-'.str_pad((string) $sequence, 3, '0', STR_PAD_LEFT),
                'descricao' => 'Servico paginado '.$sequence,
                'unidade' => 'UN',
                'quantidade_prevista' => 10,
                'valor_total' => 1000,
            ]);

            $ordem->itens()->create([
                'medicao_item_id' => $item->id,
                'quantidade_solicitada' => 10,
                'valor_previsto' => 1000,
            ]);
        }

        $this->actingAs($owner)
            ->get(route('tenant.ordem-servico.os.show', [$tenant, $ordem]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Tenant/OrdemServico/Show')
                ->has('items', 50)
                ->where('ordem.itens_count', 55)
                ->where('ordem.itens_medidos_count', 0)
                ->where('itemPagination.current_page', 1)
                ->where('itemPagination.last_page', 2)
                ->where('itemPagination.total', 55)
                ->where('itemPagination.from', 1)
                ->where('itemPagination.to', 50)
            );

        $this->actingAs($owner)
            ->get(route('tenant.ordem-servico.os.show', [
                'tenant' => $tenant,
                'ordem' => $ordem,
                'items_page' => 2,
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Tenant/OrdemServico/Show')
                ->has('items', 5)
                ->where('ordem.itens_count', 55)
                ->where('itemPagination.current_page', 2)
                ->where('itemPagination.from', 51)
                ->where('itemPagination.to', 55)
            );

        $this->actingAs($owner)
            ->get(route('tenant.ordem-servico.os.show', [
                'tenant' => $tenant,
                'ordem' => $ordem,
                'items_search' => 'ITEM-055',
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Tenant/OrdemServico/Show')
                ->has('items', 1)
                ->where('items.0.codigo', 'ITEM-055')
                ->where('itemPagination.total', 1)
                ->where('itemFilters.search', 'ITEM-055')
            );
    }

    public function test_comments_support_mentions_replies_attachments_and_pending_resolution(): void
    {
        Storage::fake('public');
        Notification::fake();
        [$tenant, $owner, $contract, $ordem, $participant] = $this->context('em_execucao', true);

        $this->actingAs($owner)
            ->post(route('tenant.ordem-servico.os.comments.store', [$tenant, $ordem]), [
                'tipo' => 'pendencia',
                'body' => 'Corrigir o acabamento antes da conclusão.',
                'mention_user_ids' => [$participant->id],
                'anexos' => [UploadedFile::fake()->create('orientacao.pdf', 20, 'application/pdf')],
            ])
            ->assertRedirect();

        $comment = $ordem->comentarios()->firstOrFail();
        Notification::assertSentTo($participant, OrdemServicoCommentNotification::class);

        $this->actingAs($participant)
            ->post(route('tenant.ordem-servico.os.comments.store', [$tenant, $ordem]), [
                'tipo' => 'pendencia',
                'parent_id' => $comment->id,
                'body' => 'Acabamento corrigido e liberado.',
            ])
            ->assertRedirect();

        $reply = $comment->replies()->firstOrFail();
        $this->actingAs($owner)
            ->post(route('tenant.ordem-servico.os.comments.store', [$tenant, $ordem]), [
                'tipo' => 'pendencia',
                'parent_id' => $reply->id,
                'body' => 'Resposta em terceiro nível não permitida.',
            ])
            ->assertNotFound();

        $this->actingAs($participant)
            ->patch(route('tenant.ordem-servico.os.comments.resolve', [$tenant, $ordem, $comment]))
            ->assertRedirect();

        $this->assertSame('resolvida', $comment->refresh()->status);
        $this->assertCount(1, $comment->replies);
        $this->assertDatabaseHas('ordem_servico_documentos', [
            'comentario_id' => $comment->id,
            'categoria' => 'comentario',
        ]);
    }

    /** @return array{Tenant, User, Contract, OrdemServico, User} */
    private function context(string $status, bool $withParticipant = false): array
    {
        $tenant = Tenant::create([
            'slug' => 'os-execucao-'.str()->lower(str()->random(6)),
            'name' => 'Tenant OS Execução',
            'plan' => 'starter',
            'status' => 'active',
        ]);
        $owner = User::factory()->create();
        $participant = User::factory()->create();
        $tenant->memberships()->create(['user_id' => $owner->id, 'role' => 'tenant_owner', 'status' => 'active']);
        $tenant->memberships()->create(['user_id' => $participant->id, 'role' => 'tenant_member', 'status' => 'active']);
        $contract = $tenant->contracts()->create(['code' => 'CT-OS-001', 'name' => 'Contrato OS', 'status' => 'active']);
        $obra = $contract->obras()->create([
            'tenant_id' => $tenant->id,
            'codigo' => '001',
            'nome' => 'Obra principal',
            'tipo' => 'pai',
        ]);

        if ($withParticipant) {
            $contract->participants()->create([
                'tenant_id' => $tenant->id,
                'user_id' => $participant->id,
                'side' => 'manager',
                'role' => 'team_member',
                'status' => 'active',
            ]);
        }

        $item = MedicaoItem::create([
            'tenant_id' => $tenant->id,
            'contract_id' => $contract->id,
            'created_by_id' => $owner->id,
            'item' => '1.1',
            'item_type' => 'manual',
            'codigo' => 'ITEM-001',
            'descricao' => 'Serviço principal',
            'unidade' => 'UN',
            'quantidade_prevista' => 10,
            'valor_total' => 1000,
        ]);
        $ordem = OrdemServico::create([
            'tenant_id' => $tenant->id,
            'contract_id' => $contract->id,
            'obra_id' => $obra->id,
            'created_by_id' => $owner->id,
            'codigo' => 'CTOS001-001-OS-001',
            'sequencial' => 1,
            'titulo' => 'Executar serviço principal',
            'custo_previsto' => 1000,
            'status' => $status,
        ]);
        OrdemServicoObraResponsavel::create([
            'tenant_id' => $tenant->id,
            'contract_id' => $contract->id,
            'obra_id' => $obra->id,
            'user_id' => $owner->id,
            'created_by_id' => $owner->id,
            'tipo' => 'fiscal',
            'status' => 'active',
        ]);
        $ordem->itens()->create([
            'medicao_item_id' => $item->id,
            'quantidade_solicitada' => 10,
            'valor_previsto' => 1000,
        ]);

        return [$tenant, $owner, $contract, $ordem, $participant];
    }
}
