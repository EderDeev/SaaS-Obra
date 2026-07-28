<?php

namespace Tests\Feature;

use App\Models\MedicaoItem;
use App\Models\OrdemServico;
use App\Models\Tenant;
use App\Models\TipoEmpresa;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class TenantMeasurementModeTest extends TestCase
{
    use RefreshDatabase;

    public function test_contract_measurement_mode_is_permanent_after_first_configuration(): void
    {
        [$tenant, $user, $contract] = $this->baseContext();

        $this->actingAs($user)
            ->patch(route('tenant.contracts.parametrizacao.update', [$tenant, $contract]), [
                'measurement_mode' => 'simple',
            ])
            ->assertRedirect();

        $this->assertSame('simple', $contract->refresh()->measurement_mode);

        $this->actingAs($user)
            ->patch(route('tenant.contracts.parametrizacao.update', [$tenant, $contract]), [
                'measurement_mode' => 'controlled',
            ])
            ->assertSessionHasErrors('measurement_mode');

        $this->assertSame('simple', $contract->refresh()->measurement_mode);
    }

    public function test_contract_links_can_be_saved_before_measurement_mode_is_defined(): void
    {
        [$tenant, $user, $contract] = $this->baseContext();

        $this->actingAs($user)
            ->patch(route('tenant.contracts.parametrizacao.update', [$tenant, $contract]), [
                'obra_id' => null,
                'cliente_empresa_id' => null,
                'construtora_empresa_id' => null,
                'gerenciadora_empresa_id' => null,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertNull($contract->refresh()->measurement_mode);
    }

    public function test_simple_measurement_creates_folha_without_os_and_uses_global_item_balance(): void
    {
        Storage::fake('public');
        [$tenant, $user, $contract] = $this->baseContext('simple');
        [$obra, $construtora, $item, $boletim] = $this->measurementContext($tenant, $user, $contract);

        $payload = [
            'obra_id' => $obra->id,
            'boletim_medicao_id' => $boletim->id,
            'construtora_empresa_id' => $construtora->id,
            'comentario' => 'Pleito direto pelo contrato.',
            'itens' => [[
                'medicao_item_id' => $item->id,
                'quantidade_pleiteada' => 6,
            ]],
            'memoria_calculo' => UploadedFile::fake()->create('memoria.zip', 64, 'application/zip'),
        ];

        $this->actingAs($user)
            ->post(route('tenant.medicao.folha-rosto.simple.store', [$tenant, $contract]), $payload)
            ->assertRedirect();

        $folha = $contract->folhasRosto()->firstOrFail();
        $this->assertNull($folha->ordem_servico_id);
        $this->assertSame($item->id, $folha->itens()->firstOrFail()->medicao_item_id);

        $payload['itens'][0]['quantidade_pleiteada'] = 5;
        $payload['memoria_calculo'] = UploadedFile::fake()->create('memoria-2.zip', 64, 'application/zip');

        $this->actingAs($user)
            ->post(route('tenant.medicao.folha-rosto.simple.store', [$tenant, $contract]), $payload)
            ->assertSessionHasErrors("itens.{$item->id}");
    }

    public function test_controlled_measurement_uses_the_same_global_balance_across_different_orders(): void
    {
        Storage::fake('public');
        [$tenant, $user, $contract] = $this->baseContext('controlled');
        [$obra, $construtora, $item, $boletim] = $this->measurementContext($tenant, $user, $contract);

        $orders = collect([1, 2])->map(function (int $sequence) use ($tenant, $contract, $obra, $user, $item): array {
            $ordem = OrdemServico::create([
                'tenant_id' => $tenant->id,
                'contract_id' => $contract->id,
                'obra_id' => $obra->id,
                'created_by_id' => $user->id,
                'codigo' => "CTR-001-OS-00{$sequence}",
                'sequencial' => $sequence,
                'titulo' => "OS {$sequence}",
                'custo_previsto' => 1000,
                'status' => 'aprovada',
            ]);
            $ordemItem = $ordem->itens()->create([
                'medicao_item_id' => $item->id,
                'quantidade_solicitada' => 10,
                'valor_previsto' => 1000,
            ]);

            return [$ordem, $ordemItem];
        });

        [$firstOrder, $firstOrderItem] = $orders[0];
        [$secondOrder, $secondOrderItem] = $orders[1];

        $this->actingAs($user)
            ->post(route('tenant.medicao.folha-rosto.store', [$tenant, $firstOrder]), [
                'boletim_medicao_id' => $boletim->id,
                'construtora_empresa_id' => $construtora->id,
                'comentario' => 'Primeiro pleito controlado.',
                'itens' => [[
                    'ordem_servico_item_id' => $firstOrderItem->id,
                    'medicao_item_id' => $item->id,
                    'quantidade_pleiteada' => 7,
                ]],
                'memoria_calculo' => UploadedFile::fake()->create('primeira.zip', 64, 'application/zip'),
            ])
            ->assertRedirect();

        $this->actingAs($user)
            ->post(route('tenant.medicao.folha-rosto.store', [$tenant, $secondOrder]), [
                'boletim_medicao_id' => $boletim->id,
                'construtora_empresa_id' => $construtora->id,
                'comentario' => 'Segundo pleito controlado.',
                'itens' => [[
                    'ordem_servico_item_id' => $secondOrderItem->id,
                    'medicao_item_id' => $item->id,
                    'quantidade_pleiteada' => 4,
                ]],
                'memoria_calculo' => UploadedFile::fake()->create('segunda.zip', 64, 'application/zip'),
            ])
            ->assertSessionHasErrors("itens.{$secondOrderItem->id}");
    }

    private function baseContext(?string $measurementMode = null): array
    {
        $tenant = Tenant::create([
            'slug' => 'measurement-mode',
            'name' => 'Measurement Mode',
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
            'code' => 'CTR-001',
            'name' => 'Contrato medição',
            'status' => 'active',
            'measurement_mode' => $measurementMode,
        ]);

        return [$tenant, $user, $contract];
    }

    private function measurementContext(Tenant $tenant, User $user, $contract): array
    {
        $tipo = TipoEmpresa::query()->firstOrCreate(['nome' => 'construtora']);
        $construtora = $contract->empresas()->create([
            'tenant_id' => $tenant->id,
            'tipo_empresa_id' => $tipo->id,
            'nome' => 'Construtora Teste',
            'cnpj' => '11.111.111/0001-11',
            'sigla' => 'CTE',
        ]);
        $obra = $contract->obras()->create([
            'tenant_id' => $tenant->id,
            'codigo' => '001',
            'nome' => 'Obra principal',
            'tipo' => 'pai',
        ]);
        $item = MedicaoItem::create([
            'tenant_id' => $tenant->id,
            'contract_id' => $contract->id,
            'created_by_id' => $user->id,
            'item' => '1.1',
            'nivel' => 2,
            'item_type' => 'manual',
            'codigo' => 'ITEM-001',
            'descricao' => 'Serviço medido',
            'unidade' => 'UN',
            'quantidade_prevista' => 10,
            'valor_unitario' => 100,
            'valor_com_bdi' => 100,
            'valor_total' => 1000,
        ]);

        $this->actingAs($user)
            ->post(route('tenant.medicao.boletim-medicao.store', $tenant), [
                'contract_id' => $contract->id,
                'periodo_referencia' => '07/26',
                'tipo' => 'normal',
            ])
            ->assertRedirect();

        return [$obra, $construtora, $item, $tenant->boletinsMedicao()->firstOrFail()];
    }
}
