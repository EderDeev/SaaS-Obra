<?php

namespace Tests\Feature;

use App\Models\MedicaoItem;
use App\Models\OrdemServico;
use App\Models\Tenant;
use App\Models\TipoEmpresa;
use App\Models\User;
use App\Notifications\FolhaRostoSubmittedForAnalysisNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class TenantFolhaRostoTest extends TestCase
{
    use RefreshDatabase;

    public function test_boletim_medicao_can_be_created_and_receive_folha_rosto(): void
    {
        Storage::fake('public');

        $tenant = Tenant::create([
            'slug' => 'empresa-bm',
            'name' => 'Empresa BM',
            'plan' => 'starter',
            'status' => 'active',
        ]);
        $user = User::factory()->create();
        $tenant->memberships()->create([
            'user_id' => $user->id,
            'role' => 'tenant_owner',
            'status' => 'active',
        ]);
        $contract = $tenant->contracts()->create([
            'code' => '011/2021-NGTM',
            'name' => 'Contrato BM',
            'status' => 'active',
        ]);
        $construtoraTipo = TipoEmpresa::query()->firstOrCreate(['nome' => 'construtora']);
        $construtora = $contract->empresas()->create([
            'tenant_id' => $tenant->id,
            'tipo_empresa_id' => $construtoraTipo->id,
            'nome' => 'Construtora BM',
            'cnpj' => '11.111.111/0001-11',
            'sigla' => 'CBM',
        ]);
        $obra = $contract->obras()->create([
            'tenant_id' => $tenant->id,
            'codigo' => '100',
            'nome' => 'Obra BM',
            'tipo' => 'pai',
        ]);
        $medicaoItem = MedicaoItem::create([
            'tenant_id' => $tenant->id,
            'contract_id' => $contract->id,
            'created_by_id' => $user->id,
            'item' => '1.1',
            'item_type' => 'manual',
            'codigo' => 'ITEM-BM',
            'descricao' => 'Serviço boletim',
            'unidade' => 'UN',
            'quantidade_prevista' => 10,
            'valor_total' => 1000,
        ]);
        $ordem = OrdemServico::create([
            'tenant_id' => $tenant->id,
            'contract_id' => $contract->id,
            'obra_id' => $obra->id,
            'created_by_id' => $user->id,
            'codigo' => '011-100-OS-001',
            'sequencial' => 1,
            'titulo' => 'OS BM',
            'custo_previsto' => 1000,
            'status' => 'aprovada',
        ]);
        $ordemItem = $ordem->itens()->create([
            'medicao_item_id' => $medicaoItem->id,
            'quantidade_solicitada' => 10,
            'valor_previsto' => 1000,
        ]);

        $this->actingAs($user)
            ->post(route('tenant.medicao.boletim-medicao.store', $tenant), [
                'contract_id' => $contract->id,
                'periodo_referencia' => '01/27',
                'tipo' => 'normal',
            ])
            ->assertRedirect();

        $boletim = $tenant->boletinsMedicao()->firstOrFail();

        $this->assertSame('BM-0001', $boletim->codigo);
        $this->assertSame('aberto_lancamento', $boletim->status);

        $this->actingAs($user)
            ->post(route('tenant.medicao.folha-rosto.store', [$tenant, $ordem]), [
                'boletim_medicao_id' => $boletim->id,
                'construtora_empresa_id' => $construtora->id,
                'comentario' => 'Pleito vinculado ao boletim.',
                'itens' => [[
                    'ordem_servico_item_id' => $ordemItem->id,
                    'quantidade_pleiteada' => 2,
                ]],
                'memoria_calculo' => UploadedFile::fake()->create(
                    'memoria-boletim.zip',
                    256,
                    'application/zip'
                ),
            ])
            ->assertRedirect();

        $this->assertSame($boletim->id, $ordem->folhasRosto()->firstOrFail()->boletim_medicao_id);
        $this->assertSame($construtora->id, $ordem->folhasRosto()->firstOrFail()->construtora_empresa_id);
        $this->assertSame('rascunho', $ordem->folhasRosto()->firstOrFail()->status);

        $fiscal = User::factory()->create();
        $tenant->memberships()->create([
            'user_id' => $fiscal->id,
            'role' => 'tenant_member',
            'status' => 'active',
        ]);

        $this->actingAs($user)
            ->post(route('tenant.medicao.analisar-pleito.responsaveis.store', $tenant), [
                'user_id' => $fiscal->id,
                'etapa' => 'fiscal',
            ])
            ->assertRedirect();

        Notification::fake();

        $folha = $ordem->folhasRosto()->firstOrFail();

        $this->actingAs($user)
            ->patch(route('tenant.medicao.folha-rosto.submit-analysis', [$tenant, $folha]))
            ->assertRedirect();

        $this->assertSame('analise_fiscal', $folha->refresh()->status);
        Notification::assertSentTo($fiscal, FolhaRostoSubmittedForAnalysisNotification::class);

        $this->actingAs($user)
            ->patch(route('tenant.medicao.boletim-medicao.freeze', [$tenant, $boletim]))
            ->assertRedirect();

        $this->assertSame('congelado', $boletim->refresh()->status);

        $this->actingAs($user)
            ->post(route('tenant.medicao.folha-rosto.store', [$tenant, $ordem]), [
                'boletim_medicao_id' => $boletim->id,
                'construtora_empresa_id' => $construtora->id,
                'comentario' => 'Pleito bloqueado por congelamento.',
                'itens' => [[
                    'ordem_servico_item_id' => $ordemItem->id,
                    'quantidade_pleiteada' => 1,
                ]],
                'memoria_calculo' => UploadedFile::fake()->create(
                    'memoria-bloqueada.zip',
                    256,
                    'application/zip'
                ),
            ])
            ->assertSessionHasErrors('boletim_medicao_id');

        $this->actingAs($user)
            ->patch(route('tenant.medicao.boletim-medicao.finish', [$tenant, $boletim]))
            ->assertRedirect();

        $this->assertSame('finalizado', $boletim->refresh()->status);

        $this->actingAs($user)
            ->patch(route('tenant.medicao.boletim-medicao.reopen', [$tenant, $boletim]))
            ->assertRedirect();

        $this->assertSame('aberto_lancamento', $boletim->refresh()->status);

        $this->actingAs($user)
            ->post(route('tenant.medicao.folha-rosto.store', [$tenant, $ordem]), [
                'boletim_medicao_id' => $boletim->id,
                'construtora_empresa_id' => $construtora->id,
                'comentario' => 'Pleito liberado apos reabertura.',
                'itens' => [[
                    'ordem_servico_item_id' => $ordemItem->id,
                    'quantidade_pleiteada' => 1,
                ]],
                'memoria_calculo' => UploadedFile::fake()->create(
                    'memoria-reaberta.zip',
                    256,
                    'application/zip'
                ),
            ])
            ->assertRedirect();

        $this->assertSame(2, $ordem->folhasRosto()->count());
    }

    public function test_memoria_calculo_zip_is_required_and_can_be_downloaded(): void
    {
        Storage::fake('public');

        $tenant = Tenant::create([
            'slug' => 'empresa-teste',
            'name' => 'Empresa Teste',
            'plan' => 'starter',
            'status' => 'active',
        ]);
        $user = User::factory()->create();
        $tenant->memberships()->create([
            'user_id' => $user->id,
            'role' => 'tenant_owner',
            'status' => 'active',
        ]);
        $contract = $tenant->contracts()->create([
            'code' => 'CT-001',
            'name' => 'Contrato Teste',
            'status' => 'active',
        ]);
        $construtoraTipo = TipoEmpresa::query()->firstOrCreate(['nome' => 'construtora']);
        $construtora = $contract->empresas()->create([
            'tenant_id' => $tenant->id,
            'tipo_empresa_id' => $construtoraTipo->id,
            'nome' => 'Construtora Teste',
            'cnpj' => '22.222.222/0001-22',
            'sigla' => 'CTE',
        ]);
        $obra = $contract->obras()->create([
            'tenant_id' => $tenant->id,
            'codigo' => '100',
            'nome' => 'Obra Teste',
            'tipo' => 'pai',
        ]);
        $medicaoItem = MedicaoItem::create([
            'tenant_id' => $tenant->id,
            'contract_id' => $contract->id,
            'created_by_id' => $user->id,
            'item' => '1.1',
            'item_type' => 'manual',
            'codigo' => 'ITEM-001',
            'descricao' => 'Serviço de teste',
            'unidade' => 'UN',
            'quantidade_prevista' => 10,
            'valor_total' => 1000,
        ]);
        $ordem = OrdemServico::create([
            'tenant_id' => $tenant->id,
            'contract_id' => $contract->id,
            'obra_id' => $obra->id,
            'created_by_id' => $user->id,
            'codigo' => 'CT001-100-OS-001',
            'sequencial' => 1,
            'titulo' => 'OS aprovada',
            'custo_previsto' => 1000,
            'status' => 'aprovada',
        ]);
        $ordemItem = $ordem->itens()->create([
            'medicao_item_id' => $medicaoItem->id,
            'quantidade_solicitada' => 10,
            'valor_previsto' => 1000,
        ]);
        $boletim = $tenant->boletinsMedicao()->create([
            'contract_id' => $contract->id,
            'created_by_id' => $user->id,
            'codigo' => 'BM-0001',
            'sequencial' => 1,
            'periodo' => '2027-01-01',
            'tipo' => 'normal',
            'status' => 'aberto_lancamento',
        ]);
        $payload = [
            'comentario' => 'Pleito da primeira medição.',
            'boletim_medicao_id' => $boletim->id,
            'construtora_empresa_id' => $construtora->id,
            'itens' => [[
                'ordem_servico_item_id' => $ordemItem->id,
                'quantidade_pleiteada' => 2,
            ]],
        ];

        $this->actingAs($user)
            ->from(route('tenant.medicao.folha-rosto.show', [$tenant, $ordem]))
            ->post(route('tenant.medicao.folha-rosto.store', [$tenant, $ordem]), $payload)
            ->assertSessionHasErrors('memoria_calculo');

        $this->actingAs($user)
            ->post(route('tenant.medicao.folha-rosto.store', [$tenant, $ordem]), [
                ...$payload,
                'memoria_calculo' => UploadedFile::fake()->create(
                    'memoria-calculo.zip',
                    256,
                    'application/zip'
                ),
            ])
            ->assertRedirect();

        $folha = $ordem->folhasRosto()->firstOrFail();

        $this->assertSame('memoria-calculo.zip', $folha->memoria_calculo_nome_original);
        Storage::disk('public')->assertExists($folha->memoria_calculo_path);

        $this->actingAs($user)
            ->get(route('tenant.medicao.folha-rosto.memoria.download', [$tenant, $folha]))
            ->assertOk()
            ->assertDownload('memoria-calculo.zip');
    }
}
