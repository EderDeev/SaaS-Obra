<?php

namespace Tests\Feature;

use App\Models\Orcamento;
use App\Models\OrcamentoEtapa;
use App\Models\OrcamentoItem;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class OrcamentoImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_tenant_admin_can_import_budget_from_synthetic_csv(): void
    {
        $tenant = Tenant::create([
            'slug' => 'teste',
            'name' => 'Empresa Teste',
            'plan' => 'starter',
            'status' => 'active',
        ]);
        $user = User::factory()->create();

        $tenant->memberships()->create([
            'user_id' => $user->id,
            'role' => 'tenant_admin',
            'status' => 'active',
        ]);

        $this->actingAs($user)
            ->get(route('tenant.orcamentos.import.create', $tenant))
            ->assertOk()
            ->assertSee('Tenant\/Orcamentos\/Import', false);

        $csv = implode("\n", [
            'Item;Código;Banco;Descrição;Und;Quant.;Valor Unit;Valor Unit com BDI;Total;Peso (%)',
            '1;;;ADMINISTRAÇÃO DE OBRA;;1;;129,78;129,78;50,00%',
            '1.1;21;Próprio;ADMINISTRAÇÃO DA OBRA_01;UND;1;100,00;129,78;129,78;50,00%',
            '2;;;SERVIÇOS PRELIMINARES;;1;;129,78;129,78;50,00%',
            '2.1;;;MOBILIZAÇÃO;;1;;129,78;129,78;50,00%',
            '2.1.1;12057;SBC;CONTAINER ESCRITÓRIO;MES;2;50,00;64,89;129,78;50,00%',
            '3;;;TESTE DE HIERARQUIA;;1;;10,00;10,00;0,00%',
            '3.1;;;GRUPO 3.1;;1;;4,00;4,00;0,00%',
            '3.1.1;A1;Próprio;ITEM DO GRUPO 3.1;UN;1;4,00;4,00;4,00;0,00%',
            '3.10;;;GRUPO 3.10;;1;;6,00;6,00;0,00%',
            '3.10.1;A10;Próprio;ITEM DO GRUPO 3.10;UN;1;6,00;6,00;6,00;0,00%',
        ]);

        $response = $this->actingAs($user)
            ->post(route('tenant.orcamentos.import.store', $tenant), [
                'codigo' => '00000001',
                'descricao' => 'Orçamento importado',
                'categoria' => 'Outros',
                'permitir_insumos_preco_zerado' => false,
                'arredondamento' => 'truncate_all_2',
                'encargos_sociais' => 'desonerado',
                'encargos_horista' => '95,00',
                'encargos_mensalista' => '53,45',
                'bdi_tipo' => 'unit_price',
                'bdi_percentual' => '29,78',
                'base_references' => [
                    [
                        'nome' => 'SINAPI',
                        'uf' => 'PA',
                        'localidade' => 'Pará',
                        'data' => '02/2025',
                    ],
                    [
                        'nome' => 'SBC',
                        'uf' => 'PA',
                        'localidade' => 'Pará',
                        'data' => '02/2025',
                    ],
                ],
                'file' => UploadedFile::fake()->createWithContent('orcamento.csv', $csv),
            ]);

        $orcamento = Orcamento::query()->where('tenant_id', $tenant->id)->firstOrFail();

        $response
            ->assertRedirect(route('tenant.orcamentos.show', [$tenant, $orcamento]))
            ->assertSessionHasNoErrors();

        $this->assertSame('29.780000', $orcamento->bdi_percentual);
        $this->assertSame('95.000000', $orcamento->encargos_horista);
        $this->assertSame('53.450000', $orcamento->encargos_mensalista);
        $this->assertSame('269.560000', $orcamento->valor_desonerado);
        $this->assertSame(['SINAPI', 'SBC'], collect($orcamento->base_references)->pluck('nome')->all());

        $this->assertSame(6, OrcamentoEtapa::query()->where('orcamento_id', $orcamento->id)->count());
        $this->assertSame(4, OrcamentoItem::query()->where('orcamento_id', $orcamento->id)->count());
        $this->assertDatabaseHas('orcamento_etapas', ['orcamento_id' => $orcamento->id, 'ordem' => '3.1']);
        $this->assertDatabaseHas('orcamento_etapas', ['orcamento_id' => $orcamento->id, 'ordem' => '3.10']);

        $nestedItem = OrcamentoItem::query()
            ->where('orcamento_id', $orcamento->id)
            ->where('codigo', '12057')
            ->firstOrFail();

        $this->assertSame('64.890000', $nestedItem->valor_com_bdi_desonerado);
        $this->assertSame('129.780000', $nestedItem->valor_total_desonerado);
        $this->assertSame('2.1', $nestedItem->etapa->ordem);
    }

    public function test_budget_import_rejects_csv_without_required_columns(): void
    {
        $tenant = Tenant::create([
            'slug' => 'teste',
            'name' => 'Empresa Teste',
            'plan' => 'starter',
            'status' => 'active',
        ]);
        $user = User::factory()->create();

        $tenant->memberships()->create([
            'user_id' => $user->id,
            'role' => 'tenant_admin',
            'status' => 'active',
        ]);

        $this->actingAs($user)
            ->post(route('tenant.orcamentos.import.store', $tenant), [
                'codigo' => '00000001',
                'descricao' => 'Orçamento inválido',
                'categoria' => 'Outros',
                'arredondamento' => 'truncate_all_2',
                'encargos_sociais' => 'desonerado',
                'bdi_tipo' => 'unit_price',
                'bdi_percentual' => '10,00',
                'base_references' => [[
                    'nome' => 'SINAPI',
                    'uf' => 'PA',
                    'data' => '02/2025',
                ]],
                'file' => UploadedFile::fake()->createWithContent('invalido.csv', "Item;Descrição\n1;Etapa"),
            ])
            ->assertSessionHasErrors('file');

        $this->assertDatabaseCount('orcamentos', 0);
    }
}
