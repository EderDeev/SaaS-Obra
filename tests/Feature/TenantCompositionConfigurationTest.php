<?php

namespace Tests\Feature;

use App\Models\OrcamentoComposicao;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantCompositionConfigurationTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_sinapi_calculation_method_creates_a_consistent_composition(): void
    {
        [$tenant, $admin] = $this->tenantWithAdmin('composition-sinapi-matrix');

        foreach (['truncate_2', 'round_2', 'none'] as $index => $method) {
            $code = 'SIN-MATRIX-'.($index + 1);

            $this->actingAs($admin)
                ->post(route('tenant.orcamentos.composicoes.store', $tenant), $this->payload($code, [
                    'modelo' => 'SINAPI',
                    'metodo_calculo' => $method,
                    'tipo_composicao' => 'PAVI - PAVIMENTACAO',
                ]))
                ->assertRedirect()
                ->assertSessionHasNoErrors();

            $composition = OrcamentoComposicao::query()->where('codigo', $code)->firstOrFail();

            $this->assertSame('SINAPI', $composition->modelo);
            $this->assertSame($method, $composition->metodo_calculo);
            $this->assertSame('PAVI - PAVIMENTACAO', $composition->tipo_composicao);
            $this->assertNull($composition->producao_equipe);
            $this->assertNull($composition->adicional_mao_obra);
            $this->assertNull($composition->fator_influencia_chuvas);
            $this->assertSame('SINAPI', $composition->base_references[0]['nome']);
        }
    }

    public function test_sicro3_parameter_combinations_use_the_official_model_structure(): void
    {
        [$tenant, $admin] = $this->tenantWithAdmin('composition-sicro-matrix');
        $cases = [
            ['1,0000', '', '', '1.000000', null, null],
            ['2,5000', '0,00', '0,0470', '2.500000', '0.000000', '0.047000'],
            ['12,345678', '18,75', '1,250000', '12.345678', '18.750000', '1.250000'],
        ];

        foreach ($cases as $index => [$production, $additionalLabor, $rainFactor, $expectedProduction, $expectedLabor, $expectedRain]) {
            $code = 'SIC-MATRIX-'.($index + 1);

            $this->actingAs($admin)
                ->post(route('tenant.orcamentos.composicoes.store', $tenant), $this->payload($code, [
                    'modelo' => 'SICRO3',
                    'tipo_composicao' => 'SICRO3',
                    'metodo_calculo' => ['truncate_2', 'round_2', 'none'][$index],
                    'producao_equipe' => $production,
                    'adicional_mao_obra' => $additionalLabor,
                    'fator_influencia_chuvas' => $rainFactor,
                ]))
                ->assertRedirect()
                ->assertSessionHasNoErrors();

            $composition = OrcamentoComposicao::query()->where('codigo', $code)->firstOrFail();

            $this->assertSame('SICRO3', $composition->modelo);
            $this->assertSame('SICRO3', $composition->tipo_composicao);
            $this->assertSame('sicro3_round_4_2', $composition->metodo_calculo);
            $this->assertSame($expectedProduction, $composition->producao_equipe);
            $this->assertSame($expectedLabor, $composition->adicional_mao_obra);
            $this->assertSame($expectedRain, $composition->fator_influencia_chuvas);
            $this->assertSame('SICRO3', $composition->base_references[0]['nome']);
        }
    }

    public function test_invalid_or_incompatible_composition_configurations_are_rejected(): void
    {
        [$tenant, $admin] = $this->tenantWithAdmin('composition-invalid-matrix');
        $invalidCases = [
            ['modelo' => 'SINAPI', 'metodo_calculo' => 'sicro3_round_4_2', 'error' => 'metodo_calculo'],
            ['modelo' => 'SINAPI', 'base_references' => [$this->reference('SICRO3')], 'error' => 'base_references'],
            ['modelo' => 'SICRO3', 'tipo_composicao' => 'SICRO3', 'producao_equipe' => '', 'error' => 'producao_equipe'],
            ['modelo' => 'SICRO3', 'tipo_composicao' => 'SICRO3', 'producao_equipe' => '0', 'error' => 'producao_equipe'],
            ['modelo' => 'SICRO3', 'tipo_composicao' => 'SICRO3', 'producao_equipe' => 'abc', 'error' => 'producao_equipe'],
            ['modelo' => 'SICRO3', 'tipo_composicao' => 'SICRO3', 'adicional_mao_obra' => '-1,00', 'error' => 'adicional_mao_obra'],
            ['modelo' => 'SICRO3', 'tipo_composicao' => 'SICRO3', 'adicional_mao_obra' => 'abc', 'error' => 'adicional_mao_obra'],
            ['modelo' => 'SICRO3', 'tipo_composicao' => 'SICRO3', 'fator_influencia_chuvas' => '-0,01', 'error' => 'fator_influencia_chuvas'],
            ['modelo' => 'SICRO3', 'tipo_composicao' => 'SICRO3', 'fator_influencia_chuvas' => 'abc', 'error' => 'fator_influencia_chuvas'],
            ['modelo' => 'SICRO3', 'tipo_composicao' => 'SICRO3', 'base_references' => [$this->reference('SINAPI')], 'error' => 'base_references'],
        ];

        foreach ($invalidCases as $index => $case) {
            $error = $case['error'];
            unset($case['error']);
            $code = 'INVALID-COMP-'.($index + 1);

            $this->actingAs($admin)
                ->post(route('tenant.orcamentos.composicoes.store', $tenant), $this->payload($code, $case))
                ->assertSessionHasErrors($error);

            $this->assertDatabaseMissing('orcamento_composicoes', [
                'tenant_id' => $tenant->id,
                'codigo' => $code,
            ]);
        }
    }

    public function test_own_sinapi_and_sicro3_compositions_expose_the_same_detail_contract_as_global_bases(): void
    {
        [$tenant, $admin] = $this->tenantWithAdmin('composition-global-parity');

        foreach (['SINAPI', 'SICRO3'] as $model) {
            $attributes = [
                'created_by_id' => $admin->id,
                'descricao' => "Composicao {$model} para paridade",
                'tipo_composicao' => $model === 'SICRO3' ? 'SICRO3' : 'PAVI - PAVIMENTACAO',
                'unidade' => 'M2',
                'uf' => 'PA',
                'modelo' => $model,
                'metodo_calculo' => $model === 'SICRO3' ? 'sicro3_round_4_2' : 'truncate_2',
                'producao_equipe' => $model === 'SICRO3' ? '2.000000' : null,
                'adicional_mao_obra' => $model === 'SICRO3' ? '5.000000' : null,
                'fator_influencia_chuvas' => $model === 'SICRO3' ? '0.047000' : null,
                'base_references' => [$this->reference($model)],
            ];
            $global = OrcamentoComposicao::create($attributes + [
                'tenant_id' => $tenant->id,
                'is_global' => true,
                'codigo' => "GLOBAL-{$model}",
            ]);
            $own = OrcamentoComposicao::create($attributes + [
                'tenant_id' => $tenant->id,
                'is_global' => false,
                'codigo' => "OWN-{$model}",
            ]);

            $globalResponse = $this->actingAs($admin)->get(route('tenant.orcamentos.composicoes.show', [$tenant, $global]));
            $ownResponse = $this->actingAs($admin)->get(route('tenant.orcamentos.composicoes.show', [$tenant, $own]));

            $globalResponse->assertOk();
            $ownResponse->assertOk();

            $globalProps = $globalResponse->viewData('page')['props'];
            $ownProps = $ownResponse->viewData('page')['props'];

            $this->assertSame(array_keys($globalProps['composicao']), array_keys($ownProps['composicao']));
            $this->assertSame(array_keys($globalProps['detail']), array_keys($ownProps['detail']));
            $this->assertSame(array_keys($globalProps['items']), array_keys($ownProps['items']));
            $this->assertSame($globalProps['composicao']['modelo'], $ownProps['composicao']['modelo']);
            $this->assertSame($globalProps['composicao']['metodo_calculo_label'], $ownProps['composicao']['metodo_calculo_label']);
            $this->assertSame($globalProps['detail']['modelo'], $ownProps['detail']['modelo']);
            $this->assertSame('global', $globalProps['composicao']['scope']);
            $this->assertSame('tenant', $ownProps['composicao']['scope']);
        }
    }

    private function tenantWithAdmin(string $slug): array
    {
        $tenant = Tenant::create([
            'slug' => $slug,
            'name' => str($slug)->headline()->toString(),
            'plan' => 'starter',
            'status' => 'active',
        ]);
        $admin = User::factory()->create();

        $tenant->memberships()->create([
            'user_id' => $admin->id,
            'role' => 'tenant_admin',
            'status' => 'active',
        ]);

        return [$tenant, $admin];
    }

    private function payload(string $code, array $overrides = []): array
    {
        $model = $overrides['modelo'] ?? 'SINAPI';

        return array_merge([
            'codigo' => $code,
            'descricao' => "Composicao de validacao {$code}",
            'tipo_composicao' => $model === 'SICRO3' ? 'SICRO3' : 'PAVI - PAVIMENTACAO',
            'unidade' => 'M2',
            'uf' => 'PA',
            'modelo' => $model,
            'metodo_calculo' => 'truncate_2',
            'producao_equipe' => $model === 'SICRO3' ? '1,0000' : '',
            'adicional_mao_obra' => '',
            'fator_influencia_chuvas' => '',
            'observacao' => 'Matriz automatizada de configuracoes',
            'base_references' => [$this->reference($model)],
        ], $overrides);
    }

    private function reference(string $model): array
    {
        return [
            'codigo' => "{$model}-PA-04/2026",
            'nome' => $model,
            'localidade' => 'Para - PA',
            'uf' => 'PA',
            'data' => '04/2026',
        ];
    }
}
