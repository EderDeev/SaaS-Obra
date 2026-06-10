<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\OrcamentoComposicao;
use App\Models\OrcamentoComposicaoAnaliticoItem;
use App\Models\OrcamentoComposicaoItem;
use App\Models\OrcamentoInsumo;
use App\Models\OrcamentoInsumoGrupo;
use App\Models\RelatorioNaoConformidadeResponsavel;
use App\Models\Tenant;
use App\Models\TipoEmpresa;
use App\Models\User;
use App\Notifications\UserTemporaryPasswordNotification;
use App\Support\ActivityPermissions;
use App\Support\ParametrizacaoPermissions;
use App\Support\ProjectPermissions;
use App\Support\RncPermissions;
use App\Support\UserPermissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TenantAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_platform_admin_can_create_tenant_with_formatted_cnpj(): void
    {
        Notification::fake();

        $platformAdmin = User::factory()->create([
            'is_platform_admin' => true,
        ]);

        $this->actingAs($platformAdmin)
            ->post(route('platform.tenants.store'), [
                'name' => 'Tenant Demo',
                'slug' => 'tenant-demo',
                'cnpj' => '12345678000190',
                'plan' => 'starter',
                'status' => 'active',
                'owner_name' => 'Owner Demo',
                'owner_email' => 'owner-demo@example.com',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('tenants', [
            'slug' => 'tenant-demo',
            'cnpj' => '12.345.678/0001-90',
        ]);

        $owner = User::where('email', 'owner-demo@example.com')->firstOrFail();

        $this->assertTrue($owner->must_change_password);
        Notification::assertSentTo($owner, UserTemporaryPasswordNotification::class);
    }

    public function test_platform_admin_sends_temporary_password_to_existing_owner_when_creating_tenant(): void
    {
        Notification::fake();

        $platformAdmin = User::factory()->create([
            'is_platform_admin' => true,
        ]);
        $owner = User::factory()->create([
            'name' => 'Owner Existente',
            'email' => 'owner-existente@example.com',
            'password' => Hash::make('SenhaAntiga1!'),
            'must_change_password' => false,
        ]);

        $this->actingAs($platformAdmin)
            ->post(route('platform.tenants.store'), [
                'name' => 'Tenant Existente',
                'slug' => 'tenant-existente',
                'cnpj' => '12345678000190',
                'plan' => 'starter',
                'status' => 'active',
                'owner_name' => 'Owner Existente',
                'owner_email' => 'owner-existente@example.com',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $owner->refresh();

        $this->assertTrue($owner->must_change_password);
        $this->assertNotNull($owner->temporary_password_created_at);
        $this->assertFalse(Hash::check('SenhaAntiga1!', $owner->password));

        Notification::assertSentTo($owner, UserTemporaryPasswordNotification::class);
    }

    public function test_platform_admin_cannot_create_tenant_with_incomplete_cnpj(): void
    {
        $platformAdmin = User::factory()->create([
            'is_platform_admin' => true,
        ]);

        $this->actingAs($platformAdmin)
            ->post(route('platform.tenants.store'), [
                'name' => 'Tenant Demo',
                'slug' => 'tenant-demo',
                'cnpj' => '12.345.678/0001',
                'plan' => 'starter',
                'status' => 'active',
                'owner_name' => 'Owner Demo',
                'owner_email' => 'owner-demo@example.com',
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('cnpj');

        $this->assertDatabaseMissing('tenants', [
            'slug' => 'tenant-demo',
        ]);
    }

    public function test_platform_admin_can_open_any_tenant_dashboard(): void
    {
        $tenant = Tenant::create([
            'slug' => 'teste',
            'name' => 'Empresa Teste',
            'plan' => 'starter',
            'status' => 'active',
        ]);
        Contract::create([
            'tenant_id' => $tenant->id,
            'code' => 'CT-001',
            'name' => 'Contrato',
            'status' => 'active',
        ]);
        $platformAdmin = User::factory()->create([
            'is_platform_admin' => true,
        ]);

        $this->actingAs($platformAdmin)
            ->get(route('tenant.dashboard', $tenant))
            ->assertOk()
            ->assertSee('Tenant\/Dashboard', false);
    }

    public function test_platform_admin_can_open_any_tenant_contract_portfolio_and_details(): void
    {
        $tenant = Tenant::create([
            'slug' => 'teste',
            'name' => 'Empresa Teste',
            'plan' => 'starter',
            'status' => 'active',
        ]);
        $contract = Contract::create([
            'tenant_id' => $tenant->id,
            'code' => 'CT-001',
            'name' => 'Contrato',
            'status' => 'active',
        ]);
        $document = $contract->projectDocuments()->create([
            'tenant_id' => $tenant->id,
            'title' => 'Projeto com revisão',
            'status' => 'em_analise',
        ]);
        $document->versions()->create([
            'tenant_id' => $tenant->id,
            'revision' => 'R00',
            'original_name' => 'projeto-r00.pdf',
            'file_path' => 'projects/projeto-r00.pdf',
        ]);
        $document->versions()->create([
            'tenant_id' => $tenant->id,
            'revision' => 'R01',
            'original_name' => 'projeto-r01.pdf',
            'file_path' => 'projects/projeto-r01.pdf',
        ]);
        $platformAdmin = User::factory()->create([
            'is_platform_admin' => true,
        ]);

        $this->actingAs($platformAdmin)
            ->get(route('tenant.contracts.index', $tenant))
            ->assertOk()
            ->assertSee('CT-001');

        $this->actingAs($platformAdmin)
            ->get(route('tenant.contracts.show', [$tenant, $contract]))
            ->assertOk()
            ->assertSee('Tenant\/Contracts\/Show', false)
            ->assertSee('R01');
    }

    public function test_platform_admin_can_open_aps_usage_panel(): void
    {
        config([
            'services.autodesk_aps.client_id' => null,
            'services.autodesk_aps.client_secret' => null,
            'services.autodesk_aps.bucket_key' => null,
        ]);

        $platformAdmin = User::factory()->create([
            'is_platform_admin' => true,
        ]);

        $this->actingAs($platformAdmin)
            ->get(route('platform.aps.index'))
            ->assertOk()
            ->assertSee('Platform\/Aps\/Index', false);
    }

    public function test_external_participant_only_sees_own_contracts(): void
    {
        $tenant = Tenant::create([
            'slug' => 'teste',
            'name' => 'Empresa Teste',
            'plan' => 'starter',
            'status' => 'active',
        ]);
        $user = User::factory()->create();

        $visible = Contract::create([
            'tenant_id' => $tenant->id,
            'code' => 'CT-001',
            'name' => 'Contrato Visivel',
            'status' => 'active',
        ]);
        Contract::create([
            'tenant_id' => $tenant->id,
            'code' => 'CT-002',
            'name' => 'Contrato Oculto',
            'status' => 'active',
        ]);

        $visible->participants()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'side' => 'client',
            'role' => 'client_viewer',
            'status' => 'active',
        ]);

        $this->actingAs($user)
            ->get(route('tenant.contracts.index', $tenant))
            ->assertOk()
            ->assertSee('Contrato Visivel')
            ->assertDontSee('Contrato Oculto');
    }

    public function test_external_participant_cannot_manage_tenant_users(): void
    {
        $tenant = Tenant::create([
            'slug' => 'teste',
            'name' => 'Empresa Teste',
            'plan' => 'starter',
            'status' => 'active',
        ]);
        $user = User::factory()->create();
        $contract = Contract::create([
            'tenant_id' => $tenant->id,
            'code' => 'CT-001',
            'name' => 'Contrato',
            'status' => 'active',
        ]);

        $contract->participants()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'side' => 'client',
            'role' => 'client_viewer',
            'status' => 'active',
        ]);

        $this->actingAs($user)
            ->get(route('tenant.users.index', $tenant))
            ->assertForbidden();
    }

    public function test_tenant_admin_can_access_user_management(): void
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
            ->get(route('tenant.users.index', $tenant))
            ->assertOk();
    }

    public function test_tenant_user_can_access_tutorials_page(): void
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
            'role' => 'engineer',
            'status' => 'active',
        ]);

        $this->actingAs($user)
            ->get(route('tenant.tutorials.index', $tenant))
            ->assertOk()
            ->assertSee('Tenant\/Tutorials\/Index', false);
    }

    public function test_tenant_user_can_access_orcamentos_pages(): void
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
            'role' => 'engineer',
            'status' => 'active',
        ]);

        $this->actingAs($user)
            ->get(route('tenant.orcamentos.index', $tenant))
            ->assertOk()
            ->assertSee('Tenant\/Orcamentos\/Index', false);

        $this->actingAs($user)
            ->get(route('tenant.orcamentos.composicoes.index', $tenant))
            ->assertOk()
            ->assertSee('Tenant\/Orcamentos\/Composicoes', false);

        $this->actingAs($user)
            ->get(route('tenant.orcamentos.insumos.index', $tenant))
            ->assertOk()
            ->assertSee('Tenant\/Orcamentos\/Insumos', false);
    }

    public function test_tenant_admin_can_create_orcamento_composicao(): void
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
            ->post(route('tenant.orcamentos.composicoes.store', $tenant), [
                'codigo' => '00000003',
                'descricao' => 'Composicao de teste',
                'tipo_composicao' => 'ASTU - ASSENTAMENTO DE TUBOS E PECAS',
                'unidade' => 'UN',
                'uf' => 'PA',
                'modelo' => 'SINAPI',
                'metodo_calculo' => 'truncate_2',
                'observacao' => 'Observacao tecnica',
                'base_references' => [
                    [
                        'codigo' => 'SINAPI-PA-04/2026',
                        'nome' => 'SINAPI',
                        'localidade' => 'Para - PA',
                        'uf' => 'PA',
                        'data' => '04/2026',
                    ],
                ],
            ])
            ->assertRedirect();

        $composicao = OrcamentoComposicao::where('codigo', '00000003')->firstOrFail();

        $this->assertSame($tenant->id, $composicao->tenant_id);
        $this->assertSame('Composicao de teste', $composicao->descricao);

        $this->actingAs($user)
            ->get(route('tenant.orcamentos.composicoes.show', [$tenant, $composicao]))
            ->assertOk()
            ->assertSee('Tenant\/Orcamentos\/Composicoes\/Show', false);

        $this->actingAs($user)
            ->get(route('tenant.orcamentos.composicoes.index', [
                $tenant,
                'searched' => 1,
                'state' => 'PA',
                'baseScope' => 'own',
                'base' => 'SINAPI',
            ]))
            ->assertOk()
            ->assertSee('Composicao de teste');

        $this->actingAs($user)
            ->get(route('tenant.orcamentos.composicoes.index', [
                $tenant,
                'searched' => 1,
                'state' => 'PA',
                'baseScope' => 'own',
                'base' => 'SINAPI',
                'search' => '00000003',
                'type' => 'ASTU - ASSENTAMENTO DE TUBOS E PECAS',
            ]))
            ->assertOk()
            ->assertSee('Composicao de teste')
            ->assertSee('ASTU - ASSENTAMENTO DE TUBOS E PECAS');

        $this->actingAs($user)
            ->get(route('tenant.orcamentos.composicoes.index', [
                $tenant,
                'searched' => 1,
                'state' => 'PA',
                'baseScope' => 'own',
                'base' => 'SINAPI',
                'search' => '00000003',
                'type' => 'PAVI - PAVIMENTACAO',
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Tenant/Orcamentos/Composicoes')
                ->where('composicoes.data', []));
    }

    public function test_tenant_admin_can_create_sicro3_orcamento_composicao(): void
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
            ->post(route('tenant.orcamentos.composicoes.store', $tenant), [
                'codigo' => 'SIC-0001',
                'descricao' => 'Composicao SICRO3 de teste',
                'tipo_composicao' => 'PAVI - PAVIMENTACAO',
                'unidade' => 'M2',
                'uf' => 'PA',
                'modelo' => 'SICRO3',
                'metodo_calculo' => 'truncate_2',
                'producao_equipe' => '1,0000',
                'adicional_mao_obra' => '2,50',
                'fator_influencia_chuvas' => '0,1250',
                'observacao' => 'Observacao SICRO3',
                'base_references' => [
                    [
                        'codigo' => 'SICRO3-PA-01/2026',
                        'nome' => 'SICRO3',
                        'localidade' => 'Para - PA',
                        'uf' => 'PA',
                        'data' => '01/2026',
                    ],
                ],
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $composicao = OrcamentoComposicao::where('codigo', 'SIC-0001')->firstOrFail();

        $this->assertFalse($composicao->is_global);
        $this->assertSame('SICRO3', $composicao->modelo);
        $this->assertSame('sicro3_round_4_2', $composicao->metodo_calculo);
        $this->assertSame('1.000000', $composicao->producao_equipe);
        $this->assertSame('2.500000', $composicao->adicional_mao_obra);
        $this->assertSame('0.125000', $composicao->fator_influencia_chuvas);

        $this->actingAs($user)
            ->get(route('tenant.orcamentos.composicoes.index', [
                $tenant,
                'searched' => 1,
                'baseScope' => 'own',
                'state' => 'PA',
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Tenant/Orcamentos/Composicoes')
                ->where('composicoes.data.0.codigo', 'SIC-0001')
                ->where('composicoes.data.0.base', 'PROPRIA')
                ->where('composicoes.data.0.base_label', 'Base propria')
                ->where('composicoes.data.0.modelo', 'SICRO3'));
    }

    public function test_sicro3_composicao_rounds_intermediate_values_and_final_total(): void
    {
        $tenant = Tenant::create([
            'slug' => 'teste',
            'name' => 'Empresa Teste',
            'plan' => 'starter',
            'status' => 'active',
        ]);
        $admin = User::factory()->create();
        $tenant->memberships()->create([
            'user_id' => $admin->id,
            'role' => 'tenant_admin',
            'status' => 'active',
        ]);
        $reference = [
            'codigo' => 'SICRO3-PA-04/2026',
            'nome' => 'SICRO3',
            'localidade' => 'Para - PA',
            'uf' => 'PA',
            'data' => '04/2026',
        ];
        $composicao = OrcamentoComposicao::create([
            'tenant_id' => $tenant->id,
            'created_by_id' => $admin->id,
            'codigo' => 'SIC-ROUND',
            'descricao' => 'Composicao SICRO3 com arredondamento',
            'tipo_composicao' => 'PAVI - PAVIMENTACAO',
            'unidade' => 'M2',
            'uf' => 'PA',
            'modelo' => 'SICRO3',
            'metodo_calculo' => 'truncate_2',
            'base_references' => [$reference],
        ]);

        foreach ([
            ['EQ001', 'Equipamento', 'H', '442.6633'],
            ['AT001', 'Atividade', 'M2', '5055.1300'],
        ] as [$code, $classificacao, $unit, $price]) {
            OrcamentoInsumo::create([
                'tenant_id' => null,
                'created_by_id' => $admin->id,
                'banco' => 'SICRO3',
                'tipo' => 'equipment',
                'classificacao' => $classificacao,
                'codigo_insumo' => $code,
                'descricao' => "Insumo {$code}",
                'unidade' => $unit,
                'uf' => 'PA',
                'origem_preco' => 'CR',
                'preco_nao_desonerado' => $price,
                'preco_desonerado' => $price,
                'data_referencia' => '2026-04-01',
            ]);
        }

        foreach ([
            ['EQ001', '0.433730'],
            ['AT001', '0.029980'],
        ] as [$code, $coefficient]) {
            OrcamentoComposicaoAnaliticoItem::create([
                'tenant_id' => null,
                'created_by_id' => $admin->id,
                'is_global' => true,
                'modelo' => 'SICRO3',
                'grupo' => 'SICRO3',
                'codigo_composicao' => 'SIC-ROUND',
                'tipo_item' => 'insumo',
                'codigo_item' => $code,
                'descricao_item' => "Insumo {$code}",
                'unidade' => 'UN',
                'data_referencia' => '2026-04-01',
                'coeficiente' => $coefficient,
            ]);
        }

        $this->actingAs($admin)
            ->get(route('tenant.orcamentos.composicoes.show', [$tenant, $composicao]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Tenant/Orcamentos/Composicoes/Show')
                ->where('composicao.metodo_calculo_label', 'SICRO3: arredondar intermediarios em 4 casas e total em 2')
                ->where('detail.states.0.items.0.preco_onerado', 191.9964)
                ->where('detail.states.0.items.1.preco_onerado', 151.5528)
                ->where('detail.states.0.computed_preco_onerado', 343.55)
                ->where('detail.states.0.effective_preco_onerado', 343.55));

        $this->actingAs($admin)
            ->get(route('tenant.orcamentos.composicoes.index', [
                $tenant,
                'searched' => 1,
                'state' => 'PA',
                'baseScope' => 'own',
                'base' => 'SICRO3',
                'search' => 'SIC-ROUND',
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Tenant/Orcamentos/Composicoes')
                ->where('composicoes.data.0.effective_preco_onerado', 343.55)
                ->where('composicoes.data.0.computed_preco_onerado', 343.55));
    }

    public function test_sicro3_manual_composicao_item_rounds_line_and_total(): void
    {
        $tenant = Tenant::create([
            'slug' => 'teste',
            'name' => 'Empresa Teste',
            'plan' => 'starter',
            'status' => 'active',
        ]);
        $admin = User::factory()->create();
        $tenant->memberships()->create([
            'user_id' => $admin->id,
            'role' => 'tenant_admin',
            'status' => 'active',
        ]);
        $composicao = OrcamentoComposicao::create([
            'tenant_id' => $tenant->id,
            'created_by_id' => $admin->id,
            'codigo' => 'SIC-MANUAL',
            'descricao' => 'Composicao SICRO3 manual',
            'tipo_composicao' => 'PAVI - PAVIMENTACAO',
            'unidade' => 'M2',
            'uf' => 'PA',
            'modelo' => 'SICRO3',
            'metodo_calculo' => 'truncate_2',
            'base_references' => [[
                'codigo' => 'SICRO3-PA-04/2026',
                'nome' => 'SICRO3',
                'uf' => 'PA',
                'data' => '04/2026',
            ]],
        ]);
        $insumo = OrcamentoInsumo::create([
            'tenant_id' => null,
            'created_by_id' => $admin->id,
            'banco' => 'SICRO3',
            'tipo' => 'equipment',
            'classificacao' => 'Equipamento',
            'codigo_insumo' => 'EQ001',
            'descricao' => 'Equipamento SICRO3',
            'unidade' => 'H',
            'uf' => 'PA',
            'origem_preco' => 'CR',
            'preco_nao_desonerado' => '442.6633',
            'preco_desonerado' => '442.6633',
            'data_referencia' => '2026-04-01',
        ]);
        OrcamentoInsumo::create([
            'tenant_id' => $tenant->id,
            'created_by_id' => $admin->id,
            'banco' => 'PROPRIA',
            'tipo' => 'equipment',
            'classificacao' => 'Equipamento',
            'codigo_insumo' => 'PROP001',
            'descricao' => 'Insumo proprio fora da base de referencia',
            'unidade' => 'H',
            'uf' => null,
            'origem_preco' => 'PR',
            'preco_nao_desonerado' => '1.00',
            'preco_desonerado' => '1.00',
            'data_referencia' => '2026-04-01',
        ]);

        $this->actingAs($admin)
            ->post(route('tenant.orcamentos.composicoes.items.store', [$tenant, $composicao]), [
                'item_type' => 'insumo',
                'source_id' => $insumo->id,
                'coeficiente' => '0,43373',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $item = OrcamentoComposicaoItem::where('orcamento_composicao_id', $composicao->id)->firstOrFail();
        $composicao->refresh();

        $this->assertSame('SICRO3', $item->base);
        $this->assertSame('191.996353', $item->preco_onerado);
        $this->assertSame('192.000000', $composicao->preco_onerado);

        $this->actingAs($admin)
            ->get(route('tenant.orcamentos.composicoes.show', [$tenant, $composicao]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Tenant/Orcamentos/Composicoes/Show')
                ->where('composicao.preco_onerado', 192)
                ->where('composicao.raw_preco_onerado', '192.000000')
                ->where('insumoOptions.0.base', 'SICRO3')
                ->missing('insumoOptions.1')
                ->where('items.0.preco_onerado', 191.9964)
                ->where('items.0.raw_preco_onerado', '191.996353'));
    }

    public function test_sicro3_manual_composicao_matches_reference_execution_and_fic_calculation(): void
    {
        $tenant = Tenant::create([
            'slug' => 'teste',
            'name' => 'Empresa Teste',
            'plan' => 'starter',
            'status' => 'active',
        ]);
        $admin = User::factory()->create();
        $tenant->memberships()->create([
            'user_id' => $admin->id,
            'role' => 'tenant_admin',
            'status' => 'active',
        ]);
        $composicao = OrcamentoComposicao::create([
            'tenant_id' => $tenant->id,
            'created_by_id' => $admin->id,
            'codigo' => 'SIC-REF',
            'descricao' => 'Composicao SICRO3 referencia',
            'tipo_composicao' => 'SICRO3',
            'unidade' => 'UN',
            'uf' => 'PA',
            'modelo' => 'SICRO3',
            'metodo_calculo' => 'sicro3_round_4_2',
            'producao_equipe' => '1.650000',
            'fator_influencia_chuvas' => '0.049300',
            'base_references' => [[
                'codigo' => 'SICRO3-PA-01/2026',
                'nome' => 'SICRO3',
                'uf' => 'PA',
                'data' => '01/2026',
            ]],
        ]);

        $insumos = collect([
            ['E9050', 'equipment', 'Equipamento', 'H', '442.6633', '442.6633', '233.4497', '233.4497', '0,39759'],
            ['P9801', 'labor', 'Mao de obra', 'H', '24.0792', '24.0792', null, null, '4'],
            ['P9830', 'labor', 'Mao de obra', 'H', '32.2057', '32.2057', null, null, '1'],
            ['M2607', 'material', 'Material', 'UN', '25289.7856', '25289.7856', null, null, '1'],
        ])->map(function (array $row) use ($admin): OrcamentoInsumo {
            [$code, $type, $classificacao, $unit, $price, $desonerado, $improdutivo, $improdutivoDesonerado] = $row;

            return OrcamentoInsumo::create([
                'tenant_id' => null,
                'created_by_id' => $admin->id,
                'banco' => 'SICRO3',
                'tipo' => $type,
                'classificacao' => $classificacao,
                'codigo_insumo' => $code,
                'descricao' => "Insumo {$code}",
                'unidade' => $unit,
                'uf' => 'PA',
                'origem_preco' => 'CR',
                'preco_nao_desonerado' => $price,
                'preco_desonerado' => $desonerado,
                'custo_improdutivo_nao_desonerado' => $improdutivo,
                'custo_improdutivo_desonerado' => $improdutivoDesonerado,
                'data_referencia' => '2026-01-01',
            ]);
        });

        foreach ($insumos as $index => $insumo) {
            $this->actingAs($admin)
                ->post(route('tenant.orcamentos.composicoes.items.store', [$tenant, $composicao]), [
                    'item_type' => 'insumo',
                    'source_id' => $insumo->id,
                    'coeficiente' => collect(['0,39759', '4', '1', '1'])->get($index),
                ])
                ->assertRedirect()
                ->assertSessionHasNoErrors();
        }
        $materialReferenceItem = OrcamentoComposicaoItem::where('orcamento_composicao_id', $composicao->id)
            ->where('codigo', 'M2607')
            ->firstOrFail();

        foreach ([
            ['1108059', 'Atividade auxiliar', 'M3', '5055.1300', '0,03816', 'atividades_auxiliares'],
            ['5915373', 'Tempo fixo', 'T', '19.9000', '0,21700', 'tempo_fixo'],
        ] as [$code, $description, $unit, $price, $coefficient, $section]) {
            $child = OrcamentoComposicao::create([
                'tenant_id' => $tenant->id,
                'created_by_id' => $admin->id,
                'codigo' => $code,
                'descricao' => $description,
                'tipo_composicao' => 'SICRO3',
                'unidade' => $unit,
                'uf' => 'PA',
                'modelo' => 'SICRO3',
                'metodo_calculo' => 'sicro3_round_4_2',
                'preco_onerado' => $price,
                'preco_desonerado' => $price,
                'base_references' => $composicao->base_references,
            ]);

            $payload = [
                'item_type' => 'composicao',
                'source_id' => $child->id,
                'coeficiente' => $coefficient,
                'sicro3_section' => $section,
            ];

            if ($section === 'tempo_fixo') {
                $payload['sicro3_referenced_item_id'] = $materialReferenceItem->id;
            }

            $this->actingAs($admin)
                ->post(route('tenant.orcamentos.composicoes.items.store', [$tenant, $composicao]), $payload)
                ->assertRedirect()
                ->assertSessionHasNoErrors();
        }

        $composicao->refresh();

        $this->assertSame('25678.180000', $composicao->preco_onerado);

        $this->actingAs($admin)
            ->get(route('tenant.orcamentos.composicoes.show', [$tenant, $composicao]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('composicao.sicro3_summary.custo_unitario_execucao_onerado', 184.5582)
                ->where('composicao.sicro3_summary.custo_fic_onerado', 6.6134)
                ->where('composicao.sicro3_summary.preco_onerado', 25678.18));
    }

    public function test_sicro3_composicao_requires_section_when_adding_child_composition(): void
    {
        $tenant = Tenant::create([
            'slug' => 'teste',
            'name' => 'Empresa Teste',
            'plan' => 'starter',
            'status' => 'active',
        ]);
        $admin = User::factory()->create();
        $tenant->memberships()->create([
            'user_id' => $admin->id,
            'role' => 'tenant_admin',
            'status' => 'active',
        ]);

        $parent = OrcamentoComposicao::create([
            'tenant_id' => $tenant->id,
            'created_by_id' => $admin->id,
            'codigo' => 'SIC-PARENT',
            'descricao' => 'Composicao SICRO3 principal',
            'tipo_composicao' => 'PAVI - PAVIMENTACAO',
            'unidade' => 'M2',
            'uf' => 'PA',
            'modelo' => 'SICRO3',
            'metodo_calculo' => 'sicro3_round_4_2',
            'base_references' => [[
                'codigo' => 'SICRO3-PA-04/2026',
                'nome' => 'SICRO3',
                'uf' => 'PA',
                'data' => '04/2026',
            ]],
        ]);
        $child = OrcamentoComposicao::create([
            'tenant_id' => $tenant->id,
            'created_by_id' => $admin->id,
            'codigo' => 'SIC-CHILD',
            'descricao' => 'Atividade auxiliar SICRO3',
            'tipo_composicao' => 'Atividades Auxiliares',
            'unidade' => 'M3',
            'uf' => 'PA',
            'modelo' => 'SICRO3',
            'metodo_calculo' => 'sicro3_round_4_2',
            'preco_onerado' => '100.123456',
            'preco_desonerado' => '90.123456',
            'base_references' => [[
                'codigo' => 'SICRO3-PA-04/2026',
                'nome' => 'SICRO3',
                'uf' => 'PA',
                'data' => '04/2026',
            ]],
        ]);
        $referencedItem = OrcamentoComposicaoItem::create([
            'tenant_id' => $tenant->id,
            'orcamento_composicao_id' => $parent->id,
            'created_by_id' => $admin->id,
            'item_type' => 'insumo',
            'sicro3_section' => 'material',
            'base' => 'SICRO3',
            'codigo' => 'MAT-REF',
            'descricao' => 'Material referenciado',
            'tipo' => 'Material',
            'unidade' => 'UN',
            'preco_unitario_onerado' => 10,
            'preco_unitario_desonerado' => 9,
            'coeficiente' => 1,
            'preco_onerado' => 10,
            'preco_desonerado' => 9,
        ]);

        $this->actingAs($admin)
            ->from(route('tenant.orcamentos.composicoes.show', [$tenant, $parent]))
            ->post(route('tenant.orcamentos.composicoes.items.store', [$tenant, $parent]), [
                'item_type' => 'composicao',
                'source_id' => $child->id,
                'coeficiente' => '1',
            ])
            ->assertRedirect(route('tenant.orcamentos.composicoes.show', [$tenant, $parent]))
            ->assertSessionHasErrors('sicro3_section');

        $this->actingAs($admin)
            ->post(route('tenant.orcamentos.composicoes.items.store', [$tenant, $parent]), [
                'item_type' => 'composicao',
                'source_id' => $child->id,
                'coeficiente' => '0,5',
                'sicro3_section' => 'tempo_fixo',
                'sicro3_referenced_item_id' => $referencedItem->id,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $item = OrcamentoComposicaoItem::where('orcamento_composicao_id', $parent->id)
            ->where('sicro3_section', 'tempo_fixo')
            ->firstOrFail();

        $this->assertSame('tempo_fixo', $item->sicro3_section);
        $this->assertSame('SICRO3', $item->base);
        $this->assertSame('50.061728', $item->preco_onerado);

        $this->actingAs($admin)
            ->get(route('tenant.orcamentos.composicoes.show', [$tenant, $parent]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Tenant/Orcamentos/Composicoes/Show')
                ->where('items.1.base', 'SICRO3')
                ->where('items.1.sicro3_section', 'tempo_fixo')
                ->where('items.1.sicro3_section_code', 'E')
                ->where('items.1.sicro3_section_label', 'Tempo Fixo')
                ->where('items.1.sicro3_referenced_item_code', 'MAT-REF'));

        $this->actingAs($admin)
            ->post(route('tenant.orcamentos.composicoes.items.store', [$tenant, $parent]), [
                'item_type' => 'composicao',
                'source_id' => $child->id,
                'coeficiente' => '1',
                'sicro3_section' => 'momento_transporte',
                'sicro3_transport_type' => 'fe',
                'sicro3_referenced_item_id' => $referencedItem->id,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $transportItem = OrcamentoComposicaoItem::where('orcamento_composicao_id', $parent->id)
            ->where('sicro3_section', 'momento_transporte')
            ->firstOrFail();

        $this->assertSame('SIC-CHILD', $transportItem->sicro3_transport_fe_code);
        $this->assertNull($transportItem->sicro3_transport_ln_code);
        $this->assertSame('MAT-REF', $transportItem->sicro3_referenced_item_code);
    }

    public function test_tenant_admin_can_add_insumo_to_orcamento_composicao(): void
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

        $composicao = OrcamentoComposicao::create([
            'tenant_id' => $tenant->id,
            'created_by_id' => $user->id,
            'codigo' => 'COMP-001',
            'descricao' => 'Composicao teste',
            'tipo_composicao' => 'SERV - SERVICOS GERAIS',
            'unidade' => 'UN',
            'uf' => 'PA',
            'modelo' => 'SINAPI',
            'metodo_calculo' => 'truncate_2',
            'base_references' => [
                [
                    'codigo' => 'SINAPI-PA-04/2026',
                    'nome' => 'SINAPI',
                    'localidade' => 'Para - PA',
                    'uf' => 'PA',
                    'data' => '04/2026',
                ],
            ],
        ]);
        $insumo = OrcamentoInsumo::create([
            'tenant_id' => null,
            'created_by_id' => $user->id,
            'banco' => 'SINAPI',
            'tipo' => 'material',
            'classificacao' => 'Material',
            'codigo_insumo' => '00001270',
            'descricao' => 'Abracadeira de teste',
            'unidade' => 'UN',
            'uf' => 'PA',
            'origem_preco' => 'CR',
            'preco_nao_desonerado' => '10.01',
            'preco_desonerado' => '8.01',
            'data_referencia' => '2026-04-01',
        ]);

        $this->actingAs($user)
            ->post(route('tenant.orcamentos.composicoes.items.store', [$tenant, $composicao]), [
                'item_type' => 'insumo',
                'source_id' => $insumo->id,
                'coeficiente' => '2,555',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(1, OrcamentoComposicaoItem::where('orcamento_composicao_id', $composicao->id)->count());
        $item = OrcamentoComposicaoItem::where('orcamento_composicao_id', $composicao->id)->firstOrFail();

        $composicao->refresh();

        $this->assertSame('25.575550', $composicao->preco_onerado);
        $this->assertSame('20.465550', $composicao->preco_desonerado);

        $this->actingAs($user)
            ->patch(route('tenant.orcamentos.composicoes.items.update', [$tenant, $composicao, $item]), [
                'coeficiente' => '3',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $item->refresh();
        $composicao->refresh();

        $this->assertSame('3.000000', $item->coeficiente);
        $this->assertSame('30.030000', $item->preco_onerado);
        $this->assertSame('24.030000', $item->preco_desonerado);
        $this->assertSame('30.030000', $composicao->preco_onerado);
        $this->assertSame('24.030000', $composicao->preco_desonerado);
    }

    public function test_composicao_show_details_analytic_items_by_state(): void
    {
        $tenant = Tenant::create([
            'slug' => 'teste',
            'name' => 'Empresa Teste',
            'plan' => 'starter',
            'status' => 'active',
        ]);
        $admin = User::factory()->create();
        $tenant->memberships()->create([
            'user_id' => $admin->id,
            'role' => 'tenant_admin',
            'status' => 'active',
        ]);
        $baseReference = fn (string $uf): array => [
            'codigo' => "SINAPI-{$uf}-04/2026",
            'nome' => 'SINAPI',
            'localidade' => "{$uf} - {$uf}",
            'uf' => $uf,
            'data' => '04/2026',
        ];
        $acre = OrcamentoComposicao::create([
            'tenant_id' => $tenant->id,
            'created_by_id' => $admin->id,
            'is_global' => true,
            'codigo' => '103333',
            'descricao' => 'Alvenaria de vedacao',
            'tipo_composicao' => 'Alvenaria de Vedacao',
            'unidade' => 'M2',
            'uf' => 'AC',
            'modelo' => 'SINAPI',
            'metodo_calculo' => 'truncate_2',
            'base_references' => [$baseReference('AC')],
            'preco_onerado' => '149.29',
            'preco_desonerado' => '143.46',
        ]);
        $para = OrcamentoComposicao::create([
            'tenant_id' => $tenant->id,
            'created_by_id' => $admin->id,
            'is_global' => true,
            'codigo' => '103333',
            'descricao' => 'Alvenaria de vedacao',
            'tipo_composicao' => 'Alvenaria de Vedacao',
            'unidade' => 'M2',
            'uf' => 'PA',
            'modelo' => 'SINAPI',
            'metodo_calculo' => 'truncate_2',
            'base_references' => [$baseReference('PA')],
            'preco_onerado' => '0.00',
            'preco_desonerado' => '0.00',
        ]);
        $childComposicoes = collect([
            ['AC', '26.88', '25.66'],
            ['PA', '32.75', '31.10'],
        ])->mapWithKeys(fn (array $data): array => [
            $data[0] => OrcamentoComposicao::create([
                'tenant_id' => $tenant->id,
                'created_by_id' => $admin->id,
                'is_global' => true,
                'codigo' => '88309',
                'descricao' => 'Pedreiro com encargos complementares',
                'tipo_composicao' => 'Livro SINAPI: Calculos e Parametros',
                'unidade' => 'H',
                'uf' => $data[0],
                'modelo' => 'SINAPI',
                'metodo_calculo' => 'truncate_2',
                'base_references' => [$baseReference($data[0])],
                'preco_onerado' => $data[1],
                'preco_desonerado' => $data[2],
            ]),
        ]);

        foreach ([['AC', '10.00', '9.00'], ['PA', '20.00', '18.00']] as [$uf, $onerado, $desonerado]) {
            OrcamentoInsumo::create([
                'tenant_id' => null,
                'created_by_id' => $admin->id,
                'banco' => 'SINAPI',
                'tipo' => 'material',
                'classificacao' => 'Material',
                'codigo_insumo' => '87369',
                'descricao' => 'Argamassa traco 1:2:8',
                'unidade' => 'M3',
                'uf' => $uf,
                'origem_preco' => 'CR',
                'preco_nao_desonerado' => $onerado,
                'preco_desonerado' => $desonerado,
                'data_referencia' => '2026-04-01',
            ]);
        }

        OrcamentoComposicaoAnaliticoItem::create([
            'tenant_id' => null,
            'created_by_id' => $admin->id,
            'is_global' => true,
            'modelo' => 'SINAPI',
            'grupo' => 'Alvenaria',
            'codigo_composicao' => '103333',
            'tipo_item' => 'insumo',
            'codigo_item' => '87369',
            'descricao_item' => 'Argamassa traco 1:2:8',
            'unidade' => 'M3',
            'data_referencia' => '2026-04-01',
            'coeficiente' => '2.000000',
        ]);
        OrcamentoComposicaoAnaliticoItem::create([
            'tenant_id' => null,
            'created_by_id' => $admin->id,
            'is_global' => true,
            'modelo' => 'SINAPI',
            'grupo' => 'Alvenaria',
            'codigo_composicao' => '103333',
            'tipo_item' => 'composicao',
            'codigo_item' => '88309',
            'descricao_item' => 'Pedreiro com encargos complementares',
            'unidade' => 'H',
            'data_referencia' => '2026-04-01',
            'coeficiente' => '1.000000',
        ]);
        OrcamentoComposicaoAnaliticoItem::create([
            'tenant_id' => null,
            'created_by_id' => $admin->id,
            'is_global' => true,
            'modelo' => 'SINAPI',
            'grupo' => 'Alvenaria',
            'codigo_composicao' => '103333',
            'tipo_item' => 'insumo',
            'codigo_item' => '999999',
            'descricao_item' => 'Insumo sem preco vinculado',
            'unidade' => 'UN',
            'data_referencia' => '2026-04-01',
            'coeficiente' => '1.000000',
        ]);

        $this->actingAs($admin)
            ->get(route('tenant.orcamentos.composicoes.show', [$tenant, $para]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Tenant/Orcamentos/Composicoes/Show')
                ->where('detail.codigo', '103333')
                ->where('detail.data', '04/2026')
                ->has('detail.states', 2)
                ->where('detail.states.0.uf', 'AC')
                ->where('detail.states.0.preco_onerado', 149.29)
                ->where('detail.states.0.items.0.codigo', '87369')
                ->where('detail.states.0.items.0.preco_unitario_onerado', 10)
                ->where('detail.states.0.items.0.preco_onerado', 20)
                ->where('detail.states.0.items.1.codigo', '88309')
                ->where('detail.states.0.items.1.composicao_id', $childComposicoes['AC']->id)
                ->where('detail.states.1.uf', 'PA')
                ->where('detail.states.1.preco_onerado', 0)
                ->where('detail.states.1.effective_preco_onerado', 72.75)
                ->where('detail.states.1.effective_preco_desonerado', 67.10)
                ->where('detail.states.1.price_source', 'analytic')
                ->where('detail.states.1.missing_price_items_count', 1)
                ->where('detail.states.1.items.0.preco_unitario_onerado', 20)
                ->where('detail.states.1.items.0.preco_onerado', 40)
                ->where('detail.states.1.items.1.codigo', '88309')
                ->where('detail.states.1.items.1.composicao_id', $childComposicoes['PA']->id));

        $this->actingAs($admin)
            ->get(route('tenant.orcamentos.composicoes.index', [
                $tenant,
                'searched' => 1,
                'state' => 'PA',
                'base' => 'SINAPI',
                'search' => '103333',
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Tenant/Orcamentos/Composicoes')
                ->where('composicoes.data.0.preco_onerado', 0)
                ->where('composicoes.data.0.effective_preco_onerado', 72.75)
                ->where('composicoes.data.0.effective_preco_desonerado', 67.10)
                ->where('composicoes.data.0.price_source', 'analytic')
                ->where('composicoes.data.0.missing_price_items_count', 1));
    }

    public function test_tenant_admin_can_create_propria_insumo_inside_orcamento_composicao(): void
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
        $grupo = OrcamentoInsumoGrupo::create([
            'tenant_id' => $tenant->id,
            'created_by_id' => $user->id,
            'nome' => 'Grupo teste',
        ]);

        $composicao = OrcamentoComposicao::create([
            'tenant_id' => $tenant->id,
            'created_by_id' => $user->id,
            'codigo' => 'COMP-002',
            'descricao' => 'Composicao com insumo proprio',
            'tipo_composicao' => 'SERV - SERVICOS GERAIS',
            'unidade' => 'UN',
            'uf' => 'PA',
            'modelo' => 'SINAPI',
            'metodo_calculo' => 'truncate_2',
            'base_references' => [
                [
                    'codigo' => 'SINAPI-PA-04/2026',
                    'nome' => 'SINAPI',
                    'localidade' => 'Para - PA',
                    'uf' => 'PA',
                    'data' => '04/2026',
                ],
            ],
        ]);

        $this->actingAs($user)
            ->post(route('tenant.orcamentos.composicoes.insumos.store', [$tenant, $composicao]), [
                'codigo_insumo' => 'PRO-001',
                'descricao' => 'Insumo proprio de teste',
                'unidade' => 'UN',
                'tipo' => 'material',
                'grupo_id' => $grupo->id,
                'uf' => 'PA',
                'preco_nao_desonerado' => '12,50',
                'preco_desonerado' => '10,00',
                'custo_improdutivo_nao_desonerado' => '9,50',
                'custo_improdutivo_desonerado' => '8,50',
                'data' => '2026-05',
                'coeficiente' => '2',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('orcamento_insumos', [
            'tenant_id' => $tenant->id,
            'banco' => 'PROPRIA',
            'codigo_insumo' => 'PRO-001',
            'grupo_id' => $grupo->id,
            'origem_preco' => 'PR',
            'data_referencia' => '2026-05-01 00:00:00',
            'custo_improdutivo_nao_desonerado' => null,
            'custo_improdutivo_desonerado' => null,
        ]);

        $this->assertDatabaseHas('orcamento_composicao_items', [
            'tenant_id' => $tenant->id,
            'orcamento_composicao_id' => $composicao->id,
            'base' => 'PROPRIA',
            'codigo' => 'PRO-001',
            'preco_onerado' => '25.00',
            'preco_desonerado' => '20.00',
        ]);
    }

    public function test_tenant_admin_can_manage_insumo_groups_and_create_manual_insumo_as_propria(): void
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
            ->post(route('tenant.orcamentos.insumos.grupos.store', $tenant), [
                'nome' => 'Equipamentos',
                'descricao' => 'Equipamentos proprios',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $grupo = OrcamentoInsumoGrupo::where('tenant_id', $tenant->id)->firstOrFail();

        $this->actingAs($user)
            ->patch(route('tenant.orcamentos.insumos.grupos.update', [$tenant, $grupo]), [
                'nome' => 'Equipamentos pesados',
                'descricao' => 'Equipamentos de campo',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $grupo->refresh();

        $this->assertSame('Equipamentos pesados', $grupo->nome);

        $this->actingAs($user)
            ->post(route('tenant.orcamentos.insumos.store', $tenant), [
                'tipo' => 'equipment',
                'grupo_id' => $grupo->id,
                'codigo_insumo' => 'EQP-001',
                'descricao' => 'Rolo compactador',
                'unidade' => 'H',
                'uf' => 'PA',
                'preco_nao_desonerado' => '120,99',
                'preco_desonerado' => '',
                'custo_improdutivo_nao_desonerado' => '55,25',
                'custo_improdutivo_desonerado' => '50,10',
                'data' => '06/2026',
                'observacao' => 'Observacao operacional do insumo',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $insumo = OrcamentoInsumo::where('tenant_id', $tenant->id)
            ->where('codigo_insumo', 'EQP-001')
            ->firstOrFail();

        $this->assertSame('PROPRIA', $insumo->banco);
        $this->assertSame('PR', $insumo->origem_preco);
        $this->assertSame($grupo->id, $insumo->grupo_id);
        $this->assertSame('Observacao operacional do insumo', $insumo->observacao);
        $this->assertNull($insumo->preco_desonerado);
        $this->assertSame('55.250000', $insumo->custo_improdutivo_nao_desonerado);
        $this->assertSame('50.100000', $insumo->custo_improdutivo_desonerado);

        $this->actingAs($user)
            ->delete(route('tenant.orcamentos.insumos.grupos.destroy', [$tenant, $grupo]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSoftDeleted('orcamento_insumo_grupos', ['id' => $grupo->id]);
        $this->assertDatabaseHas('orcamento_insumos', [
            'id' => $insumo->id,
            'grupo_id' => null,
        ]);
    }

    public function test_tenant_admin_can_import_base_propria_insumos_with_mapped_columns(): void
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
        $grupo = OrcamentoInsumoGrupo::create([
            'tenant_id' => $tenant->id,
            'created_by_id' => $user->id,
            'nome' => 'Equipamentos pesados',
        ]);
        $csv = implode("\n", [
            'tipo;codigo;grupo;descricao;unidade;preco_desonerado;preco_nao_desonerado',
            'Equipamento;EQP-001;Equipamentos pesados;Rolo compactador;H;100,25;120,99',
            'Equipamento;EQP-002;Equipamentos pesados;Escavadeira hidraulica;H;101,25;121,99',
            'Equipamento;EQP-001;Equipamentos pesados;Rolo compactador duplicado;H;102,25;122,99',
        ]);

        $this->actingAs($user)
            ->post(route('tenant.orcamentos.insumos.import', $tenant), [
                'scope' => 'tenant',
                'file' => UploadedFile::fake()->createWithContent('base-propria.csv', $csv),
                'first_item_row' => 2,
                'last_item_row' => 4,
                'data' => '06/2026',
                'tipo_column' => 'A',
                'codigo_column' => 'B',
                'grupo_column' => 'C',
                'descricao_column' => 'D',
                'unidade_column' => 'E',
                'preco_desonerado_column' => 'F',
                'preco_nao_desonerado_column' => 'G',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors()
            ->assertSessionHas('import_result.scope_label', 'Base propria')
            ->assertSessionHas('import_result.created', 3)
            ->assertSessionHas('import_result.duplicated', 0);

        $insumo = OrcamentoInsumo::where('tenant_id', $tenant->id)
            ->where('codigo_insumo', 'EQP-001')
            ->whereNull('uf')
            ->orderBy('id')
            ->firstOrFail();

        $this->assertSame('PROPRIA', $insumo->banco);
        $this->assertSame('equipment', $insumo->tipo);
        $this->assertSame('Equipamento', $insumo->classificacao);
        $this->assertSame($grupo->id, $insumo->grupo_id);
        $this->assertNull($insumo->uf);
        $this->assertSame('2026-06-01', $insumo->data_referencia->toDateString());
        $this->assertSame('120.990000', $insumo->preco_nao_desonerado);
        $this->assertSame('100.250000', $insumo->preco_desonerado);
        $this->assertDatabaseHas('orcamento_insumos', [
            'tenant_id' => $tenant->id,
            'banco' => 'PROPRIA',
            'codigo_insumo' => 'EQP-002',
            'uf' => null,
            'preco_nao_desonerado' => '121.990000',
        ]);
        $this->assertSame(2, OrcamentoInsumo::where('tenant_id', $tenant->id)
            ->where('codigo_insumo', 'EQP-001')
            ->whereNull('uf')
            ->count());

        $duplicateCsv = implode("\n", [
            'tipo;codigo;grupo;descricao;unidade;preco_desonerado;preco_nao_desonerado',
            'Equipamento;EQP-001;Equipamentos pesados;Descricao alterada;H;200,00;300,00',
        ]);

        $this->actingAs($user)
            ->post(route('tenant.orcamentos.insumos.import', $tenant), [
                'scope' => 'tenant',
                'file' => UploadedFile::fake()->createWithContent('base-propria-duplicada.csv', $duplicateCsv),
                'first_item_row' => 2,
                'last_item_row' => 2,
                'data' => '06/2026',
                'tipo_column' => 'A',
                'codigo_column' => 'B',
                'grupo_column' => 'C',
                'descricao_column' => 'D',
                'unidade_column' => 'E',
                'preco_desonerado_column' => 'F',
                'preco_nao_desonerado_column' => 'G',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors()
            ->assertSessionHas('import_result.created', 1)
            ->assertSessionHas('import_result.duplicated', 0);

        $this->assertSame('Rolo compactador', $insumo->refresh()->descricao);
        $this->assertSame('120.990000', $insumo->preco_nao_desonerado);
        $this->assertSame(3, OrcamentoInsumo::where('tenant_id', $tenant->id)
            ->where('codigo_insumo', 'EQP-001')
            ->whereNull('uf')
            ->count());
    }

    public function test_insumo_type_filter_options_follow_selected_bank(): void
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

        OrcamentoInsumo::create([
            'tenant_id' => null,
            'created_by_id' => $user->id,
            'banco' => 'SINAPI',
            'tipo' => 'material',
            'classificacao' => 'Material',
            'codigo_insumo' => 'SIN-001',
            'descricao' => 'Insumo SINAPI',
            'unidade' => 'UN',
            'uf' => 'PA',
            'origem_preco' => 'CR',
            'preco_nao_desonerado' => 10,
            'preco_desonerado' => 9,
            'data_referencia' => '2026-04-01',
        ]);
        OrcamentoInsumo::create([
            'tenant_id' => null,
            'created_by_id' => $user->id,
            'banco' => 'SICRO3',
            'tipo' => 'equipment',
            'classificacao' => 'Equipamento',
            'codigo_insumo' => 'SIC-001',
            'descricao' => 'Insumo SICRO3',
            'unidade' => 'H',
            'uf' => 'PA',
            'origem_preco' => null,
            'preco_nao_desonerado' => 20,
            'preco_desonerado' => 18,
            'data_referencia' => '2026-04-01',
        ]);

        $this->actingAs($user)
            ->get(route('tenant.orcamentos.insumos.index', [
                $tenant,
                'bank' => 'SINAPI',
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Tenant/Orcamentos/Insumos')
                ->where('typeOptions.0.value', 'Material')
                ->missing('typeOptions.1')
                ->where('typeOptionsByBank.SINAPI.0.value', 'Material')
                ->where('typeOptionsByBank.SICRO3.0.value', 'Equipamento'));

        $this->actingAs($user)
            ->get(route('tenant.orcamentos.insumos.index', [
                $tenant,
                'bank' => 'SICRO3',
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Tenant/Orcamentos/Insumos')
                ->where('typeOptions.0.value', 'Equipamento')
                ->missing('typeOptions.1'));
    }

    public function test_platform_admin_can_import_global_orcamento_composicoes(): void
    {
        $tenant = Tenant::create([
            'slug' => 'teste',
            'name' => 'Empresa Teste',
            'plan' => 'starter',
            'status' => 'active',
        ]);
        $otherTenant = Tenant::create([
            'slug' => 'outro',
            'name' => 'Outro Tenant',
            'plan' => 'starter',
            'status' => 'active',
        ]);
        $platformAdmin = User::factory()->create([
            'is_platform_admin' => true,
        ]);
        $csv = implode("\n", [
            'grupo_composicao;codigo_composicao;descricao;unidade;uf;preco_nao_desonerado;preco_desonerado;data;;',
            'Acessibilidade;104658;PISO PODOTATIL DE ALERTA OU DIRECIONAL, DE CONCRETO;M2;PA;182,839;180,229;abr/26;;',
        ]);

        $this->actingAs($platformAdmin)
            ->post(route('tenant.orcamentos.composicoes.import', $tenant), [
                'scope' => 'global',
                'modelo' => 'SINAPI',
                'file' => UploadedFile::fake()->createWithContent('composicoes.csv', $csv),
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors()
            ->assertSessionHas('import_result.created', 1)
            ->assertSessionHas('import_result.updated', 0)
            ->assertSessionHas('import_result.skipped', 0);

        $this->assertDatabaseHas('orcamento_composicoes', [
            'tenant_id' => $tenant->id,
            'is_global' => true,
            'codigo' => '104658',
            'tipo_composicao' => 'Acessibilidade',
            'uf' => 'PA',
            'modelo' => 'SINAPI',
            'preco_onerado' => 182.839,
            'preco_desonerado' => 180.229,
        ]);

        $this->actingAs($platformAdmin)
            ->get(route('tenant.orcamentos.composicoes.index', [
                $otherTenant,
                'searched' => 1,
                'state' => 'PA',
                'base' => 'SINAPI',
            ]))
            ->assertOk()
            ->assertSee('104658');
    }

    public function test_budget_csv_imports_preserve_windows_1252_portuguese_accents(): void
    {
        $tenant = Tenant::create([
            'slug' => 'teste',
            'name' => 'Empresa Teste',
            'plan' => 'starter',
            'status' => 'active',
        ]);
        $platformAdmin = User::factory()->create([
            'is_platform_admin' => true,
        ]);
        $windowsDescricao = "V\xE1lvulas para Redes de Saneamento";
        $windowsDescricaoComposicao = "ARMA\xC7\xC3O DO SISTEMA DE PAREDES DE CONCRETO";
        $windowsDescricaoAnalitico = "PLANTIO DE \xC1RVORE ORNAMENTAL";
        $windowsDescricaoComNbsp = "LAJE X\xA08\xA0CM";
        $expectedDescricao = 'Válvulas para Redes de Saneamento';

        $this->actingAs($platformAdmin)
            ->post(route('tenant.orcamentos.insumos.import', $tenant), [
                'scope' => 'global',
                'banco' => 'SINAPI',
                'first_item_row' => 2,
                'last_item_row' => 3,
                'codigo_insumo_column' => 'A',
                'classificacao_column' => 'B',
                'descricao_column' => 'C',
                'unidade_column' => 'D',
                'uf_column' => 'E',
                'origem_preco_column' => 'F',
                'preco_nao_desonerado_column' => 'G',
                'preco_desonerado_column' => 'H',
                'data_column' => 'I',
                'file' => UploadedFile::fake()->createWithContent('insumos.csv', implode("\n", [
                    'codigo_insumo;classificacao;descricao;unidade;uf;origem_preco;preco_nao_desonerado;preco_desonerado;data',
                    '99999;Material;'.$windowsDescricao.';UN;PA;CR;1,00;1,00;abr/26',
                    '99998;Material;'.$windowsDescricaoComNbsp.';UN;PA;CR;1,00;1,00;abr/26',
                ])),
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->actingAs($platformAdmin)
            ->post(route('tenant.orcamentos.composicoes.import', $tenant), [
                'scope' => 'global',
                'modelo' => 'SINAPI',
                'file' => UploadedFile::fake()->createWithContent('composicoes.csv', implode("\n", [
                    'grupo_composicao;codigo_composicao;descricao;unidade;uf;preco_nao_desonerado;preco_desonerado;data',
                    'Saneamento;88888;'.$windowsDescricaoComposicao.';UN;PA;2,00;2,00;abr/26',
                ])),
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->actingAs($platformAdmin)
            ->post(route('tenant.orcamentos.composicoes.import-analitico', $tenant), [
                'scope' => 'global',
                'modelo' => 'SINAPI',
                'file' => UploadedFile::fake()->createWithContent('analitico.csv', implode("\n", [
                    'Grupo;Codigo da composicao;Tipo Item;Codigo do item;Descricao;Unidade;Coeficiente;Data',
                    'Saneamento;88888;INSUMO;99999;'.$windowsDescricaoAnalitico.';UN;1;abr/26',
                ])),
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('orcamento_insumos', [
            'descricao' => $expectedDescricao,
        ]);
        $this->assertDatabaseHas('orcamento_insumos', [
            'descricao' => 'LAJE X 8 CM',
        ]);
        $this->assertDatabaseHas('orcamento_composicoes', [
            'descricao' => 'ARMAÇÃO DO SISTEMA DE PAREDES DE CONCRETO',
        ]);
        $this->assertDatabaseHas('orcamento_composicao_analitico_items', [
            'tenant_id' => null,
            'is_global' => true,
            'descricao_item' => 'PLANTIO DE ÁRVORE ORNAMENTAL',
        ]);
    }

    public function test_tenant_admin_can_import_composicao_analitico_by_codes(): void
    {
        $tenant = Tenant::create([
            'slug' => 'teste',
            'name' => 'Empresa Teste',
            'plan' => 'starter',
            'status' => 'active',
        ]);
        $admin = User::factory()->create();
        $tenant->users()->attach($admin->id, ['role' => 'tenant_admin', 'status' => 'active']);
        $parent = OrcamentoComposicao::create([
            'tenant_id' => $tenant->id,
            'created_by_id' => $admin->id,
            'codigo' => '104658',
            'descricao' => 'Piso podotatil',
            'tipo_composicao' => 'Acessibilidade',
            'unidade' => 'M2',
            'uf' => 'PA',
            'modelo' => 'SINAPI',
            'metodo_calculo' => 'truncate_2',
            'base_references' => [[
                'codigo' => 'SINAPI-PA-04/2026',
                'nome' => 'SINAPI',
                'uf' => 'PA',
                'data' => '04/2026',
            ]],
        ]);
        $child = OrcamentoComposicao::create([
            'tenant_id' => $tenant->id,
            'created_by_id' => $admin->id,
            'codigo' => '88316',
            'descricao' => 'Servente com encargos complementares',
            'tipo_composicao' => 'Livro SINAPI',
            'unidade' => 'H',
            'uf' => 'PA',
            'modelo' => 'SINAPI',
            'metodo_calculo' => 'truncate_2',
            'base_references' => [[
                'codigo' => 'SINAPI-PA-04/2026',
                'nome' => 'SINAPI',
                'uf' => 'PA',
                'data' => '04/2026',
            ]],
            'preco_onerado' => '26.88',
            'preco_desonerado' => '25.66',
        ]);
        $insumo = OrcamentoInsumo::create([
            'tenant_id' => null,
            'created_by_id' => $admin->id,
            'banco' => 'SINAPI',
            'tipo' => 'material',
            'classificacao' => 'Material',
            'codigo_insumo' => '36178',
            'descricao' => 'Piso tatil',
            'unidade' => 'UN',
            'uf' => 'PA',
            'origem_preco' => 'CR',
            'preco_nao_desonerado' => '10.00',
            'preco_desonerado' => '9.00',
            'data_referencia' => '2026-04-01',
        ]);
        $csv = implode("\n", [
            'Grupo;Codigo da composicao;Tipo Item;Codigo do item;Descricao;Unidade;Coeficiente;Data',
            'Acessibilidade;104658;;;PISO PODOTATIL;M2;;abr/26',
            'Acessibilidade;104658;COMPOSICAO;88316;SERVENTE COM ENCARGOS;H;1,279;abr/26',
            'Acessibilidade;104658;INSUMO;36178;PISO TATIL;UN;6,4375;abr/26',
        ]);

        $this->actingAs($admin)
            ->post(route('tenant.orcamentos.composicoes.import-analitico', $tenant), [
                'scope' => 'tenant',
                'modelo' => 'SINAPI',
                'file' => UploadedFile::fake()->createWithContent('analitico.csv', $csv),
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors()
            ->assertSessionHas('import_result.created', 2)
            ->assertSessionHas('import_result.updated', 0)
            ->assertSessionHas('import_result.skipped', 0);

        $this->assertDatabaseHas('orcamento_composicao_analitico_items', [
            'tenant_id' => $tenant->id,
            'codigo_composicao' => '104658',
            'tipo_item' => 'composicao',
            'codigo_item' => '88316',
            'coeficiente' => '1.279000',
        ]);
        $this->assertDatabaseHas('orcamento_composicao_analitico_items', [
            'tenant_id' => $tenant->id,
            'codigo_composicao' => '104658',
            'tipo_item' => 'insumo',
            'codigo_item' => '36178',
            'coeficiente' => '6.437500',
        ]);
        $this->assertSame(0, OrcamentoComposicaoItem::count());

        $parent->refresh();

        $this->assertSame('0.000000', $parent->preco_onerado);
        $this->assertSame('0.000000', $parent->preco_desonerado);
    }

    public function test_composicao_analitico_import_requires_reference_date_column(): void
    {
        $tenant = Tenant::create([
            'slug' => 'teste',
            'name' => 'Empresa Teste',
            'plan' => 'starter',
            'status' => 'active',
        ]);
        $admin = User::factory()->create();
        $tenant->users()->attach($admin->id, ['role' => 'tenant_admin', 'status' => 'active']);
        $csv = implode("\n", [
            'Grupo;Codigo da composicao;Tipo Item;Codigo do item;Descricao;Unidade;Coeficiente',
            'Acessibilidade;104658;INSUMO;36178;PISO TATIL;UN;6,4375',
        ]);

        $this->actingAs($admin)
            ->from(route('tenant.orcamentos.composicoes.index', $tenant))
            ->post(route('tenant.orcamentos.composicoes.import-analitico', $tenant), [
                'scope' => 'tenant',
                'modelo' => 'SINAPI',
                'file' => UploadedFile::fake()->createWithContent('analitico.csv', $csv),
            ])
            ->assertRedirect(route('tenant.orcamentos.composicoes.index', $tenant))
            ->assertSessionHasErrors('file');
    }

    public function test_composicao_analitico_accepts_tab_delimited_excel_headers(): void
    {
        $tenant = Tenant::create([
            'slug' => 'teste',
            'name' => 'Empresa Teste',
            'plan' => 'starter',
            'status' => 'active',
        ]);
        $admin = User::factory()->create();
        $tenant->users()->attach($admin->id, ['role' => 'tenant_admin', 'status' => 'active']);
        $csv = implode("\n", [
            implode("\t", ['grupo', 'codigo_composicao', 'tipo_item', 'codigo_item', 'descricao', 'unidade', 'coeficiente', 'data']),
            implode("\t", ['Acessibilidade', '104658', '', '', 'PISO PODOTATIL', 'M2', '', 'abr/26']),
            implode("\t", ['Acessibilidade', '104658', 'COMPOSICAO', '88316', 'SERVENTE COM ENCARGOS', 'H', '1,279', 'abr/26']),
            implode("\t", ['Acessibilidade', '104658', 'INSUMO', '36178', 'PISO TATIL', 'UN', '6,4375', 'abr/26']),
        ]);

        $this->actingAs($admin)
            ->post(route('tenant.orcamentos.composicoes.import-analitico', $tenant), [
                'scope' => 'tenant',
                'modelo' => 'SINAPI',
                'file' => UploadedFile::fake()->createWithContent('analitico.tsv', $csv),
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors()
            ->assertSessionHas('import_result.created', 2)
            ->assertSessionHas('import_result.skipped', 0);

        $this->assertDatabaseHas('orcamento_composicao_analitico_items', [
            'tenant_id' => $tenant->id,
            'codigo_composicao' => '104658',
            'tipo_item' => 'composicao',
            'codigo_item' => '88316',
            'data_referencia' => '2026-04-01 00:00:00',
        ]);
        $this->assertDatabaseHas('orcamento_composicao_analitico_items', [
            'tenant_id' => $tenant->id,
            'codigo_composicao' => '104658',
            'tipo_item' => 'insumo',
            'codigo_item' => '36178',
            'data_referencia' => '2026-04-01 00:00:00',
        ]);
    }

    public function test_platform_admin_can_import_global_composicao_analitico_without_tenant_id(): void
    {
        $tenant = Tenant::create([
            'slug' => 'teste',
            'name' => 'Empresa Teste',
            'plan' => 'starter',
            'status' => 'active',
        ]);
        $platformAdmin = User::factory()->create([
            'is_platform_admin' => true,
        ]);
        $csv = implode("\n", [
            'Grupo;Codigo da composicao;Tipo Item;Codigo do item;Descricao;Unidade;Coeficiente;Data',
            'Acessibilidade;104658;INSUMO;36178;PISO TATIL;UN;6,4375;abr/26',
        ]);

        $this->actingAs($platformAdmin)
            ->post(route('tenant.orcamentos.composicoes.import-analitico', $tenant), [
                'scope' => 'global',
                'modelo' => 'SINAPI',
                'file' => UploadedFile::fake()->createWithContent('analitico.csv', $csv),
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors()
            ->assertSessionHas('import_result.created', 1);

        $record = OrcamentoComposicaoAnaliticoItem::firstOrFail();

        $this->assertNull($record->tenant_id);
        $this->assertTrue((bool) $record->is_global);
        $this->assertSame(0, OrcamentoComposicaoItem::count());
    }

    public function test_composicao_analitico_stores_reference_date_without_materializing_items(): void
    {
        $tenant = Tenant::create([
            'slug' => 'teste',
            'name' => 'Empresa Teste',
            'plan' => 'starter',
            'status' => 'active',
        ]);
        $admin = User::factory()->create();
        $tenant->users()->attach($admin->id, ['role' => 'tenant_admin', 'status' => 'active']);
        $parent = OrcamentoComposicao::create([
            'tenant_id' => $tenant->id,
            'created_by_id' => $admin->id,
            'codigo' => '104658',
            'descricao' => 'Piso podotatil maio',
            'tipo_composicao' => 'Acessibilidade',
            'unidade' => 'M2',
            'uf' => 'PA',
            'modelo' => 'SINAPI',
            'metodo_calculo' => 'truncate_2',
            'base_references' => [[
                'codigo' => 'SINAPI-PA-05/2026',
                'nome' => 'SINAPI',
                'uf' => 'PA',
                'data' => '05/2026',
            ]],
        ]);
        $aprilChild = OrcamentoComposicao::create([
            'tenant_id' => $tenant->id,
            'created_by_id' => $admin->id,
            'codigo' => '88316',
            'descricao' => 'Servente abril',
            'tipo_composicao' => 'Livro SINAPI',
            'unidade' => 'H',
            'uf' => 'PA',
            'modelo' => 'SINAPI',
            'metodo_calculo' => 'truncate_2',
            'base_references' => [[
                'codigo' => 'SINAPI-PA-04/2026',
                'nome' => 'SINAPI',
                'uf' => 'PA',
                'data' => '04/2026',
            ]],
            'preco_onerado' => '26.88',
            'preco_desonerado' => '25.66',
        ]);
        $mayChild = OrcamentoComposicao::create([
            'tenant_id' => $tenant->id,
            'created_by_id' => $admin->id,
            'codigo' => '88316',
            'descricao' => 'Servente maio',
            'tipo_composicao' => 'Livro SINAPI',
            'unidade' => 'H',
            'uf' => 'PA',
            'modelo' => 'SINAPI',
            'metodo_calculo' => 'truncate_2',
            'base_references' => [[
                'codigo' => 'SINAPI-PA-05/2026',
                'nome' => 'SINAPI',
                'uf' => 'PA',
                'data' => '05/2026',
            ]],
            'preco_onerado' => '30.00',
            'preco_desonerado' => '28.00',
        ]);
        $csv = implode("\n", [
            'Grupo;Codigo da composicao;Tipo Item;Codigo do item;Descricao;Unidade;Coeficiente;Data',
            'Acessibilidade;104658;COMPOSICAO;88316;SERVENTE COM ENCARGOS;H;2;mai/26',
        ]);

        $this->actingAs($admin)
            ->post(route('tenant.orcamentos.composicoes.import-analitico', $tenant), [
                'scope' => 'tenant',
                'modelo' => 'SINAPI',
                'file' => UploadedFile::fake()->createWithContent('analitico.csv', $csv),
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('orcamento_composicao_analitico_items', [
            'tenant_id' => $tenant->id,
            'codigo_composicao' => '104658',
            'tipo_item' => 'composicao',
            'codigo_item' => '88316',
            'coeficiente' => '2.000000',
            'data_referencia' => '2026-05-01 00:00:00',
        ]);
        $this->assertSame(0, OrcamentoComposicaoItem::count());
    }

    public function test_platform_admin_can_import_global_insumos_with_portuguese_month_reference(): void
    {
        $tenant = Tenant::create([
            'slug' => 'teste',
            'name' => 'Empresa Teste',
            'plan' => 'starter',
            'status' => 'active',
        ]);
        $platformAdmin = User::factory()->create([
            'is_platform_admin' => true,
        ]);
        $csv = implode("\n", [
            ';;;;;;;;',
            ';;;;;;;;',
            ';;;;;;;;',
            ';;;;;;;;',
            'codigo_insumo;classificacao;descricao;unidade;uf;origem_preco;preco_nao_desonerado;preco_desonerado;data',
            '45333;Servicos;ABERTURA PARA ENCAIXE DE CUBA;UN;AC;CR;302,089;302,089;abr/26',
            '45333;Servicos;ABERTURA PARA ENCAIXE DE CUBA;UN;AC;CR;305,10;305,10;mai/26',
            ';;;;;;;;',
            ';;;;;;;;',
        ]);

        $this->actingAs($platformAdmin)
            ->post(route('tenant.orcamentos.insumos.import', $tenant), [
                'scope' => 'global',
                'banco' => 'SINAPI',
                'first_item_row' => 6,
                'last_item_row' => 7,
                'codigo_insumo_column' => 'A',
                'classificacao_column' => 'B',
                'descricao_column' => 'C',
                'unidade_column' => 'D',
                'uf_column' => 'E',
                'origem_preco_column' => 'F',
                'preco_nao_desonerado_column' => 'G',
                'preco_desonerado_column' => 'H',
                'data_column' => 'I',
                'file' => UploadedFile::fake()->createWithContent('insumo_resumido.csv', $csv),
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors()
            ->assertSessionHas('import_result.read', 2)
            ->assertSessionHas('import_result.skipped', 0);

        $this->assertDatabaseHas('orcamento_insumos', [
            'tenant_id' => null,
            'banco' => 'SINAPI',
            'codigo_insumo' => '45333',
            'uf' => 'AC',
            'origem_preco' => 'CR',
            'data_referencia' => '2026-04-01 00:00:00',
            'preco_nao_desonerado' => 302.089,
        ]);
        $this->assertDatabaseHas('orcamento_insumos', [
            'tenant_id' => null,
            'banco' => 'SINAPI',
            'codigo_insumo' => '45333',
            'uf' => 'AC',
            'origem_preco' => 'CR',
            'data_referencia' => '2026-05-01 00:00:00',
            'preco_nao_desonerado' => '305.10',
        ]);
        $this->assertSame(2, OrcamentoInsumo::where('codigo_insumo', '45333')->count());
    }

    public function test_platform_admin_can_import_global_insumos_with_classificacao_column(): void
    {
        $tenant = Tenant::create([
            'slug' => 'teste',
            'name' => 'Empresa Teste',
            'plan' => 'starter',
            'status' => 'active',
        ]);
        $platformAdmin = User::factory()->create([
            'is_platform_admin' => true,
        ]);
        $csv = implode("\n", [
            'codigo_insumo;classificacao;descricao;unidade;uf;origem_preco;preco_nao_desonerado;preco_desonerado;data',
            '45333;SERVI'.chr(128).'OS;ABERTURA PARA ENCAIXE DE CUBA;UN;AC;;302,08;302,08;abr/26',
        ]);

        $this->actingAs($platformAdmin)
            ->post(route('tenant.orcamentos.insumos.import', $tenant), [
                'scope' => 'global',
                'banco' => 'SINAPI',
                'first_item_row' => 2,
                'last_item_row' => 2,
                'codigo_insumo_column' => 'A',
                'classificacao_column' => 'B',
                'descricao_column' => 'C',
                'unidade_column' => 'D',
                'uf_column' => 'E',
                'origem_preco_column' => 'F',
                'preco_nao_desonerado_column' => 'G',
                'preco_desonerado_column' => 'H',
                'data_column' => 'I',
                'file' => UploadedFile::fake()->createWithContent('insumos_resumido.csv', $csv),
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('orcamento_insumos', [
            'tenant_id' => null,
            'banco' => 'SINAPI',
            'codigo_insumo' => '45333',
            'uf' => 'AC',
            'origem_preco' => null,
            'classificacao' => 'Servicos',
            'tipo' => 'service',
            'data_referencia' => '2026-04-01 00:00:00',
        ]);
    }

    public function test_platform_admin_can_import_global_sicro3_insumos_without_optional_improdutivo_costs(): void
    {
        $tenant = Tenant::create([
            'slug' => 'teste',
            'name' => 'Empresa Teste',
            'plan' => 'starter',
            'status' => 'active',
        ]);
        $platformAdmin = User::factory()->create([
            'is_platform_admin' => true,
        ]);
        $csv = implode("\n", [
            'codigo_insumo;classificacao;descricao;unidade;uf;origem_preco;preco_nao_desonerado;preco_desonerado;data',
            'EQP001;Equipamento;Escavadeira hidraulica;H;PA;CR;120,50;110,40;04/2026',
        ]);

        $this->actingAs($platformAdmin)
            ->post(route('tenant.orcamentos.insumos.import', $tenant), [
                'scope' => 'global',
                'banco' => 'SICRO3',
                'first_item_row' => 2,
                'last_item_row' => 2,
                'codigo_insumo_column' => 'A',
                'classificacao_column' => 'B',
                'descricao_column' => 'C',
                'unidade_column' => 'D',
                'uf_column' => 'E',
                'origem_preco_column' => 'F',
                'preco_nao_desonerado_column' => 'G',
                'preco_desonerado_column' => 'H',
                'data_column' => 'I',
                'file' => UploadedFile::fake()->createWithContent('sicro3.csv', $csv),
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors()
            ->assertSessionHas('import_result.created', 1)
            ->assertSessionHas('import_result.skipped', 0);

        $this->assertDatabaseHas('orcamento_insumos', [
            'tenant_id' => null,
            'banco' => 'SICRO3',
            'codigo_insumo' => 'EQP001',
            'uf' => 'PA',
            'classificacao' => 'Equipamento',
            'tipo' => 'equipment',
            'preco_nao_desonerado' => '120.500000',
            'preco_desonerado' => '110.400000',
            'custo_improdutivo_nao_desonerado' => null,
            'custo_improdutivo_desonerado' => null,
            'data_referencia' => '2026-04-01 00:00:00',
        ]);
    }

    public function test_tenant_user_can_access_quality_rnc_page(): void
    {
        $tenant = Tenant::create([
            'slug' => 'teste',
            'name' => 'Empresa Teste',
            'plan' => 'starter',
            'status' => 'active',
        ]);
        $user = User::factory()->create();
        $contract = $tenant->contracts()->create([
            'code' => 'CT-001',
            'name' => 'Contrato',
            'status' => 'active',
        ]);

        $tenant->memberships()->create([
            'user_id' => $user->id,
            'role' => 'engineer',
            'status' => 'active',
        ]);
        RelatorioNaoConformidadeResponsavel::create([
            'tenant_id' => $tenant->id,
            'contract_id' => $contract->id,
            'user_id' => $user->id,
            'status' => 'active',
            'permissions' => [RncPermissions::VIEW],
        ]);

        $this->actingAs($user)
            ->get(route('tenant.qualidade.rnc.index', $tenant))
            ->assertOk()
            ->assertSee('Tenant\/Qualidade\/RelatorioNaoConformidade\/Index', false);
    }

    public function test_tenant_admin_can_create_user_linked_to_empresa(): void
    {
        $tenant = Tenant::create([
            'slug' => 'teste',
            'name' => 'Empresa Teste',
            'plan' => 'starter',
            'status' => 'active',
        ]);
        $admin = User::factory()->create();
        $contract = Contract::create([
            'tenant_id' => $tenant->id,
            'code' => 'CT-001',
            'name' => 'Contrato',
            'status' => 'active',
        ]);
        $tipo = TipoEmpresa::where('nome', 'gerenciadora')->firstOrFail();
        $empresa = $tenant->empresas()->create([
            'contract_id' => $contract->id,
            'tipo_empresa_id' => $tipo->id,
            'nome' => 'Gerenciadora Alfa',
            'cnpj' => '44.444.444/0001-44',
            'sigla' => 'ALFA',
        ]);

        $tenant->memberships()->create([
            'user_id' => $admin->id,
            'role' => 'tenant_admin',
            'status' => 'active',
        ]);

        $this->actingAs($admin)
            ->post(route('tenant.users.store', $tenant), [
                'name' => 'Usuario Operacional',
                'email' => 'operacional@example.com',
                'empresa_id' => $empresa->id,
                'role' => 'engineer',
            ])
            ->assertRedirect();

        $user = User::where('email', 'operacional@example.com')->firstOrFail();

        $this->assertDatabaseHas('tenant_users', [
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'empresa_id' => $empresa->id,
            'role' => 'engineer',
            'status' => 'active',
        ]);
    }

    public function test_tenant_admin_can_update_user_membership(): void
    {
        $tenant = Tenant::create([
            'slug' => 'teste',
            'name' => 'Empresa Teste',
            'plan' => 'starter',
            'status' => 'active',
        ]);
        $admin = User::factory()->create();
        $managedUser = User::factory()->create([
            'name' => 'Usuario Antigo',
            'email' => 'usuario-antigo@example.com',
        ]);
        $contract = Contract::create([
            'tenant_id' => $tenant->id,
            'code' => 'CT-001',
            'name' => 'Contrato',
            'status' => 'active',
        ]);
        $tipo = TipoEmpresa::where('nome', 'gerenciadora')->firstOrFail();
        $empresaA = $tenant->empresas()->create([
            'contract_id' => $contract->id,
            'tipo_empresa_id' => $tipo->id,
            'nome' => 'Gerenciadora Alfa',
            'cnpj' => '44.444.444/0001-44',
            'sigla' => 'ALFA',
        ]);
        $empresaB = $tenant->empresas()->create([
            'contract_id' => $contract->id,
            'tipo_empresa_id' => $tipo->id,
            'nome' => 'Gerenciadora Beta',
            'cnpj' => '55.555.555/0001-55',
            'sigla' => 'BETA',
        ]);

        $tenant->memberships()->create([
            'user_id' => $admin->id,
            'role' => 'tenant_admin',
            'status' => 'active',
        ]);
        $membership = $tenant->memberships()->create([
            'user_id' => $managedUser->id,
            'empresa_id' => $empresaA->id,
            'role' => 'engineer',
            'status' => 'active',
        ]);

        $this->actingAs($admin)
            ->patch(route('tenant.users.update', [$tenant, $membership]), [
                'name' => 'Usuario Editado',
                'email' => 'usuario-editado@example.com',
                'empresa_id' => $empresaB->id,
                'role' => 'financial',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('users', [
            'id' => $managedUser->id,
            'name' => 'Usuario Editado',
            'email' => 'usuario-editado@example.com',
        ]);
        $this->assertDatabaseHas('tenant_users', [
            'id' => $membership->id,
            'tenant_id' => $tenant->id,
            'user_id' => $managedUser->id,
            'empresa_id' => $empresaB->id,
            'role' => 'financial',
            'status' => 'active',
        ]);
    }

    public function test_tenant_admin_can_deactivate_user_membership(): void
    {
        $tenant = Tenant::create([
            'slug' => 'teste',
            'name' => 'Empresa Teste',
            'plan' => 'starter',
            'status' => 'active',
        ]);
        $admin = User::factory()->create();
        $managedUser = User::factory()->create();

        $tenant->memberships()->create([
            'user_id' => $admin->id,
            'role' => 'tenant_admin',
            'status' => 'active',
        ]);
        $membership = $tenant->memberships()->create([
            'user_id' => $managedUser->id,
            'role' => 'engineer',
            'status' => 'active',
        ]);

        $this->actingAs($admin)
            ->patch(route('tenant.users.deactivate', [$tenant, $membership]))
            ->assertRedirect();

        $this->assertDatabaseHas('tenant_users', [
            'id' => $membership->id,
            'tenant_id' => $tenant->id,
            'user_id' => $managedUser->id,
            'status' => 'inactive',
        ]);
    }

    public function test_tenant_admin_can_create_contract_with_currency(): void
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
            ->post(route('tenant.contracts.store', $tenant), [
                'code' => 'ct-usd-001',
                'total_value' => '1250.50',
                'currency' => 'USD',
                'city' => 'Campinas',
                'state' => 'SP',
                'starts_at' => '2026-06-01',
                'ends_at' => '2026-12-31',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('contracts', [
            'tenant_id' => $tenant->id,
            'code' => 'CT-USD-001',
            'currency' => 'USD',
            'city' => 'Campinas',
            'state' => 'SP',
        ]);

        $contract = Contract::where('tenant_id', $tenant->id)->where('code', 'CT-USD-001')->firstOrFail();

        $this->assertSame('1250.50', $contract->total_value);
        $this->assertSame('2026-06-01', $contract->starts_at->toDateString());
        $this->assertSame('2026-12-31', $contract->ends_at->toDateString());
    }

    public function test_internal_operational_user_only_sees_linked_contracts(): void
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
            'role' => 'engineer',
            'status' => 'active',
        ]);

        $visible = Contract::create([
            'tenant_id' => $tenant->id,
            'code' => 'CT-001',
            'name' => 'Contrato Visivel',
            'status' => 'active',
        ]);
        $hidden = Contract::create([
            'tenant_id' => $tenant->id,
            'code' => 'CT-002',
            'name' => 'Contrato Oculto',
            'status' => 'active',
        ]);

        $visible->participants()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'side' => 'manager',
            'role' => 'team_member',
            'status' => 'active',
        ]);

        $this->actingAs($user)
            ->get(route('tenant.contracts.index', $tenant))
            ->assertOk()
            ->assertSee('Contrato Visivel')
            ->assertDontSee('Contrato Oculto');

        $this->actingAs($user)
            ->get(route('tenant.contracts.show', [$tenant, $visible]))
            ->assertOk();

        $this->actingAs($user)
            ->get(route('tenant.contracts.show', [$tenant, $hidden]))
            ->assertForbidden();
    }

    public function test_tenant_owner_can_open_permissions_page(): void
    {
        $tenant = Tenant::create([
            'slug' => 'teste',
            'name' => 'Empresa Teste',
            'plan' => 'starter',
            'status' => 'active',
        ]);
        $owner = User::factory()->create();

        $tenant->memberships()->create([
            'user_id' => $owner->id,
            'role' => 'tenant_owner',
            'status' => 'active',
        ]);

        $this->actingAs($owner)
            ->get(route('tenant.permissions.index', $tenant))
            ->assertOk()
            ->assertSee('Tenant\/Permissions\/Index', false);
    }

    public function test_tenant_owner_can_update_user_permissions_by_contract(): void
    {
        $tenant = Tenant::create([
            'slug' => 'teste',
            'name' => 'Empresa Teste',
            'plan' => 'starter',
            'status' => 'active',
        ]);
        $owner = User::factory()->create();
        $managedUser = User::factory()->create();
        $contract = Contract::create([
            'tenant_id' => $tenant->id,
            'code' => 'CT-001',
            'name' => 'Contrato',
            'status' => 'active',
        ]);

        $tenant->memberships()->create([
            'user_id' => $owner->id,
            'role' => 'tenant_owner',
            'status' => 'active',
        ]);
        $membership = $tenant->memberships()->create([
            'user_id' => $managedUser->id,
            'role' => 'engineer',
            'status' => 'active',
        ]);
        $participant = $contract->participants()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $managedUser->id,
            'side' => 'manager',
            'role' => 'team_member',
            'status' => 'active',
        ]);

        $this->actingAs($owner)
            ->patch(route('tenant.permissions.update', $tenant), [
                'user_id' => $managedUser->id,
                'contract_id' => $contract->id,
                'activity_permissions' => [
                    ActivityPermissions::VIEW,
                    ActivityPermissions::CREATE,
                ],
                'project_permissions' => [
                    ProjectPermissions::VIEW,
                    ProjectPermissions::UPLOAD,
                ],
                'rnc_permissions' => [
                    RncPermissions::VIEW,
                    RncPermissions::EDIT,
                ],
                'user_permissions' => [
                    UserPermissions::VIEW,
                ],
                'parametrizacao_permissions' => [
                    ParametrizacaoPermissions::EMPRESAS,
                ],
            ])
            ->assertRedirect();

        $participant->refresh();
        $membership->refresh();
        $responsavel = RelatorioNaoConformidadeResponsavel::where('tenant_id', $tenant->id)
            ->where('contract_id', $contract->id)
            ->where('user_id', $managedUser->id)
            ->firstOrFail();

        $this->assertSame([ActivityPermissions::VIEW, ActivityPermissions::CREATE], $participant->activity_permissions);
        $this->assertSame([ProjectPermissions::VIEW, ProjectPermissions::UPLOAD], $participant->project_permissions);
        $this->assertSame([UserPermissions::VIEW], $membership->user_permissions);
        $this->assertSame([ParametrizacaoPermissions::VIEW, ParametrizacaoPermissions::EMPRESAS], $membership->parametrizacao_permissions);
        $this->assertSame([RncPermissions::VIEW, RncPermissions::EDIT], $responsavel->permissions);
        $this->assertSame('active', $responsavel->status);
    }

    public function test_user_with_parametrizacao_empresas_permission_can_only_open_empresas_submenu(): void
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
            'role' => 'engineer',
            'status' => 'active',
            'parametrizacao_permissions' => [
                ParametrizacaoPermissions::VIEW,
                ParametrizacaoPermissions::EMPRESAS,
            ],
        ]);
        $tenant->contracts()->create([
            'code' => 'CT-001',
            'name' => 'Contrato',
            'status' => 'active',
        ]);

        $this->actingAs($user)
            ->get(route('tenant.parametrizacao.empresas.index', $tenant))
            ->assertOk();

        $this->actingAs($user)
            ->get(route('tenant.parametrizacao.obras.index', $tenant))
            ->assertForbidden();

        $this->actingAs($user)
            ->get(route('tenant.parametrizacao.disciplinas.index', $tenant))
            ->assertForbidden();
    }

    public function test_user_without_parametrizacao_permission_cannot_open_parametrizacao(): void
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
            'role' => 'engineer',
            'status' => 'active',
            'parametrizacao_permissions' => [],
        ]);

        $this->actingAs($user)
            ->get(route('tenant.parametrizacao.empresas.index', $tenant))
            ->assertForbidden();
    }
}
