<?php

namespace Tests\Feature;

use App\Models\RdoConfiguracao;
use App\Models\RdoDiario;
use App\Models\RdoResponsavel;
use App\Models\RdoSignatureRequest;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\RdoFlowChangedNotification;
use App\Services\RdoDailyGenerator;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class TenantRdoTest extends TestCase
{
    use RefreshDatabase;

    public function test_rdo_configuration_can_be_saved_and_generates_today(): void
    {
        [$tenant, $user, $contract, $obra] = $this->scenario();
        $today = CarbonImmutable::now('America/Sao_Paulo');

        $response = $this->actingAs($user)
            ->post(route('tenant.diario-obra.rdo.settings.store', $tenant), [
                'contract_id' => $contract->id,
                'obra_ids' => [$obra->id],
                'responsible_user_id' => $user->id,
                'start_date' => $today->toDateString(),
                'end_date' => null,
                'generation_time' => '00:00',
                'timezone' => 'America/Sao_Paulo',
                'generation_weekdays' => [0, 1, 2, 3, 4, 5, 6],
                'generate_on_holidays' => true,
                'copy_previous_day' => true,
                'copy_workforce' => true,
                'copy_equipment' => true,
                'copy_pending_activities' => true,
                'require_photos' => false,
                'submission_deadline_days' => 7,
                'active' => true,
            ]);

        $response->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('rdo_configuracoes', [
            'tenant_id' => $tenant->id,
            'contract_id' => $contract->id,
            'obra_id' => $obra->id,
            'copy_previous_day' => true,
            'submission_deadline_days' => 7,
        ]);
        $this->assertDatabaseHas('rdo_configuracao_obras', [
            'obra_id' => $obra->id,
        ]);
        $rdo = RdoDiario::query()
            ->where('tenant_id', $tenant->id)
            ->where('obra_id', $obra->id)
            ->firstOrFail();
        $this->assertSame($today->toDateString(), $rdo->reference_date->toDateString());
        $this->assertSame('rascunho', $rdo->status);
    }

    public function test_daily_generation_is_idempotent_and_respects_configured_time(): void
    {
        [$tenant, $user, $contract, $obra] = $this->scenario();
        $configuration = $this->configuration($tenant->id, $contract->id, $obra->id, $user->id, [
            'generation_time' => '08:00',
        ]);
        $generator = app(RdoDailyGenerator::class);

        $this->assertSame(0, $generator->generateDue(CarbonImmutable::parse('2026-06-23 07:59:00', 'America/Sao_Paulo')->utc()));
        $this->assertSame(1, $generator->generateDue(CarbonImmutable::parse('2026-06-23 08:00:00', 'America/Sao_Paulo')->utc()));
        $this->assertSame(0, $generator->generateDue(CarbonImmutable::parse('2026-06-23 09:00:00', 'America/Sao_Paulo')->utc()));

        $this->assertSame(1, RdoDiario::query()->where('rdo_configuracao_id', $configuration->id)->count());
    }

    public function test_configuration_accepts_multiple_service_fronts_and_generates_one_consolidated_rdo(): void
    {
        [$tenant, $user, $contract, $obra] = $this->scenario();
        $secondObra = $contract->obras()->create([
            'tenant_id' => $tenant->id,
            'obra_pai_id' => $obra->id,
            'codigo' => '101',
            'nome' => 'Frente de Serviço 01',
            'tipo' => 'filha',
        ]);

        $response = $this->actingAs($user)
            ->post(route('tenant.diario-obra.rdo.settings.store', $tenant), [
                'contract_id' => $contract->id,
                'obra_ids' => [$obra->id, $secondObra->id],
                'responsible_user_id' => $user->id,
                'start_date' => now()->toDateString(),
                'end_date' => null,
                'generation_time' => '00:00',
                'timezone' => 'America/Sao_Paulo',
                'generation_weekdays' => [0, 1, 2, 3, 4, 5, 6],
                'generate_on_holidays' => true,
                'copy_previous_day' => false,
                'copy_workforce' => true,
                'copy_equipment' => true,
                'copy_pending_activities' => true,
                'require_photos' => false,
                'submission_deadline_days' => 7,
                'active' => true,
            ]);

        $response->assertRedirect()->assertSessionHasNoErrors();
        $configuration = RdoConfiguracao::query()->firstOrFail();
        $this->assertEqualsCanonicalizing(
            [$obra->id, $secondObra->id],
            $configuration->obras()->pluck('obras.id')->all(),
        );
        $this->assertSame(1, RdoDiario::query()->count());
    }

    public function test_new_rdo_keeps_reference_to_previous_day_when_copy_is_enabled(): void
    {
        [$tenant, $user, $contract, $obra] = $this->scenario();
        $configuration = $this->configuration($tenant->id, $contract->id, $obra->id, $user->id, [
            'copy_previous_day' => true,
        ]);
        $generator = app(RdoDailyGenerator::class);

        $first = $generator->generateForConfiguration($configuration, CarbonImmutable::parse('2026-06-22'), false, $user->id);
        $second = $generator->generateForConfiguration($configuration, CarbonImmutable::parse('2026-06-23'), false, $user->id);

        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $this->assertSame($first->id, $second->copied_from_rdo_id);
        $this->assertSame(1, $first->sequence_number);
        $this->assertSame(2, $second->sequence_number);
    }

    public function test_rdo_catalogs_can_register_labor_equipment_and_subcontractors(): void
    {
        [$tenant, $user] = $this->scenario();

        $this->actingAs($user)
            ->post(route('tenant.diario-obra.rdo.cadastros.mao-obra.store', $tenant), [
                'descricao' => 'Pedreiro',
                'tipo' => 'direta',
                'unidade' => 'pessoa',
                'active' => true,
            ])
            ->assertSessionHasNoErrors();

        $this->actingAs($user)
            ->post(route('tenant.diario-obra.rdo.cadastros.equipamentos.store', $tenant), [
                'codigo' => 'EQ-001',
                'descricao' => 'Escavadeira hidráulica',
                'unidade' => 'unidade',
                'propriedade' => 'locado',
                'active' => true,
            ])
            ->assertSessionHasNoErrors();

        $this->actingAs($user)
            ->post(route('tenant.diario-obra.rdo.cadastros.subcontratadas.store', $tenant), [
                'razao_social' => 'Subcontratada Teste Ltda.',
                'nome_fantasia' => 'Sub Teste',
                'cnpj' => '12.345.678/0001-90',
                'responsavel' => 'Responsável Teste',
                'telefone' => '(91) 99999-9999',
                'email' => 'contato@subteste.test',
                'active' => true,
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('rdo_mao_obra_cadastros', [
            'tenant_id' => $tenant->id,
            'descricao' => 'Pedreiro',
            'tipo' => 'direta',
        ]);
        $this->assertDatabaseHas('rdo_equipamento_cadastros', [
            'tenant_id' => $tenant->id,
            'codigo' => 'EQ-001',
            'propriedade' => 'locado',
        ]);
        $this->assertDatabaseHas('rdo_subcontratada_cadastros', [
            'tenant_id' => $tenant->id,
            'cnpj' => '12.345.678/0001-90',
        ]);
    }

    public function test_rdo_section_modal_data_can_be_saved(): void
    {
        [$tenant, $user, $contract, $obra] = $this->scenario();
        $configuration = $this->configuration($tenant->id, $contract->id, $obra->id, $user->id);
        $configuration->obras()->attach($obra->id);
        $rdo = app(RdoDailyGenerator::class)->generateForConfiguration(
            $configuration,
            CarbonImmutable::parse('2026-06-24'),
            false,
            $user->id,
        );

        $this->actingAs($user)
            ->post(route('tenant.diario-obra.rdo.sections.store', [$tenant, $rdo, 'clima']), [
                'obra_id' => $obra->id,
                'dados' => [
                    'manha' => 'ensolarado',
                    'tarde' => 'nublado',
                    'noite' => 'chuvoso',
                    'precipitacao_manha_mm' => '0',
                    'precipitacao_tarde_mm' => '12.50',
                    'precipitacao_noite_mm' => '3.20',
                    'observacoes' => 'Chuva após as 17h.',
                    'dia_impraticavel' => false,
                ],
            ])
            ->assertSessionHasNoErrors();

        $section = $rdo->secoes()->where('obra_id', $obra->id)->where('secao', 'clima')->firstOrFail();
        $this->assertSame('ensolarado', $section->dados['manha']);
        $this->assertSame('Chuva após as 17h.', $section->dados['observacoes']);
        $this->assertSame('12.50', $section->dados['precipitacao_tarde_mm']);
    }

    public function test_same_rdo_section_is_stored_separately_for_each_obra(): void
    {
        [$tenant, $user, $contract, $obra] = $this->scenario();
        $secondObra = $contract->obras()->create([
            'tenant_id' => $tenant->id,
            'obra_pai_id' => $obra->id,
            'codigo' => '101',
            'nome' => 'Frente 02',
            'tipo' => 'filha',
        ]);
        $configuration = $this->configuration($tenant->id, $contract->id, $obra->id, $user->id);
        $configuration->obras()->attach([$obra->id, $secondObra->id]);
        $rdo = app(RdoDailyGenerator::class)->generateForConfiguration(
            $configuration,
            CarbonImmutable::parse('2026-06-25'),
            false,
            $user->id,
        );

        foreach ([
            [$obra->id, 'Atividade da obra principal'],
            [$secondObra->id, 'Atividade da frente 02'],
        ] as [$obraId, $description]) {
            $this->actingAs($user)
                ->post(route('tenant.diario-obra.rdo.sections.store', [$tenant, $rdo, 'atividades']), [
                    'obra_id' => $obraId,
                    'dados' => ['atividades' => [['titulo' => 'Serviço', 'ocorrencia' => $description]]],
                ])
                ->assertSessionHasNoErrors();
        }

        $this->assertSame(2, $rdo->secoes()->where('secao', 'atividades')->count());
        $this->assertSame(
            'Atividade da frente 02',
            $rdo->secoes()->where('obra_id', $secondObra->id)->where('secao', 'atividades')->firstOrFail()->dados['atividades'][0]['ocorrencia'],
        );
    }

    public function test_complete_rdo_form_saves_all_sections_for_one_obra(): void
    {
        [$tenant, $user, $contract, $obra] = $this->scenario();
        $configuration = $this->configuration($tenant->id, $contract->id, $obra->id, $user->id);
        $configuration->obras()->attach($obra->id);
        $rdo = app(RdoDailyGenerator::class)->generateForConfiguration(
            $configuration,
            CarbonImmutable::parse('2026-06-25'),
            false,
            $user->id,
        );

        $sections = [
            'clima' => ['manha' => 'ensolarado'],
            'mao_obra' => ['efetivos' => ['1' => 5]],
            'equipamentos' => ['registros' => []],
            'atividades' => ['atividades' => [['titulo' => 'Serviço', 'ocorrencia' => 'Serviço executado']]],
            'fotos' => ['legenda' => 'Frente de serviço', 'arquivos' => []],
            'comentarios' => ['construtora' => 'Sem ocorrências'],
        ];

        $this->actingAs($user)
            ->post(route('tenant.diario-obra.rdo.sections.store-all', [$tenant, $rdo]), [
                'obra_id' => $obra->id,
                'secoes' => $sections,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(6, $rdo->secoes()->where('obra_id', $obra->id)->count());
    }

    public function test_new_rdo_can_copy_all_sections_from_an_existing_rdo(): void
    {
        [$tenant, $user, $contract, $obra] = $this->scenario();
        $configuration = $this->configuration($tenant->id, $contract->id, $obra->id, $user->id);
        $configuration->obras()->attach($obra->id);
        $source = app(RdoDailyGenerator::class)->generateForConfiguration(
            $configuration,
            CarbonImmutable::parse('2026-06-24'),
            false,
            $user->id,
        );
        $source->secoes()->create([
            'tenant_id' => $tenant->id,
            'obra_id' => $obra->id,
            'updated_by_id' => $user->id,
            'secao' => 'atividades',
            'dados' => ['atividades' => [['titulo' => 'Base', 'ocorrencia' => 'Base copiada']]],
        ]);

        $this->actingAs($user)
            ->post(route('tenant.diario-obra.rdo.generate', $tenant), [
                'configuration_id' => $configuration->id,
                'reference_date' => '2026-06-25',
                'copy_from_rdo_id' => $source->id,
            ])
            ->assertSessionHasNoErrors();

        $copied = RdoDiario::query()->whereDate('reference_date', '2026-06-25')->firstOrFail();
        $this->assertSame($source->id, $copied->copied_from_rdo_id);
        $this->assertSame(
            'Base copiada',
            $copied->secoes()->where('secao', 'atividades')->firstOrFail()->dados['atividades'][0]['ocorrencia'],
        );
    }

    public function test_rdo_photos_can_have_comments_be_reordered_and_removed(): void
    {
        Storage::fake('public');
        [$tenant, $user, $contract, $obra] = $this->scenario();
        $configuration = $this->configuration($tenant->id, $contract->id, $obra->id, $user->id);
        $configuration->obras()->attach($obra->id);
        $rdo = app(RdoDailyGenerator::class)->generateForConfiguration(
            $configuration,
            CarbonImmutable::parse('2026-06-25'),
            false,
            $user->id,
        );

        $this->actingAs($user)
            ->post(route('tenant.diario-obra.rdo.sections.store', [$tenant, $rdo, 'fotos']), [
                'obra_id' => $obra->id,
                'dados' => [
                    'arquivos' => [],
                    'novas_fotos' => [
                        ['client_id' => 'foto-a', 'comment' => 'Primeira foto'],
                        ['client_id' => 'foto-b', 'comment' => 'Segunda foto'],
                    ],
                    'ordem_fotos' => ['new:foto-b', 'new:foto-a'],
                ],
                'fotos' => [
                    UploadedFile::fake()->image('a.jpg'),
                    UploadedFile::fake()->image('b.jpg'),
                ],
            ])
            ->assertSessionHasNoErrors();

        $section = $rdo->secoes()->where('secao', 'fotos')->firstOrFail();
        $this->assertSame('Segunda foto', $section->dados['arquivos'][0]['comment']);
        $this->assertSame(1, $section->dados['arquivos'][0]['position']);
        $remaining = $section->dados['arquivos'][0];

        $this->actingAs($user)
            ->post(route('tenant.diario-obra.rdo.sections.store', [$tenant, $rdo, 'fotos']), [
                'obra_id' => $obra->id,
                'dados' => [
                    'arquivos' => [[...$remaining, 'comment' => 'Comentário alterado']],
                    'novas_fotos' => [],
                    'ordem_fotos' => ['existing:'.$remaining['path']],
                ],
            ])
            ->assertSessionHasNoErrors();

        $section->refresh();
        $this->assertCount(1, $section->dados['arquivos']);
        $this->assertSame('Comentário alterado', $section->dados['arquivos'][0]['comment']);
    }

    public function test_rdo_flows_from_constructor_to_manager_client_and_archive(): void
    {
        [$tenant, $user, $contract, $obra] = $this->scenario();
        $configuration = $this->configuration($tenant->id, $contract->id, $obra->id, $user->id);
        $configuration->obras()->attach($obra->id);
        $rdo = app(RdoDailyGenerator::class)->generateForConfiguration(
            $configuration,
            CarbonImmutable::parse('2026-06-25'),
            false,
            $user->id,
        );

        foreach (['clima', 'mao_obra', 'equipamentos', 'atividades', 'fotos', 'comentarios'] as $section) {
            $rdo->secoes()->create([
                'tenant_id' => $tenant->id,
                'obra_id' => $obra->id,
                'updated_by_id' => $user->id,
                'secao' => $section,
                'dados' => $section === 'comentarios' ? ['construtora' => 'RDO preenchido'] : [],
            ]);
        }

        $this->actingAs($user)
            ->post(route('tenant.diario-obra.rdo.flow', [$tenant, $rdo]), [
                'action' => 'submit',
                'comment' => 'Encaminhado pela construtora.',
            ])
            ->assertSessionHasNoErrors();
        $this->assertSame('em_aprovacao', $rdo->fresh()->status);

        $this->actingAs($user)
            ->post(route('tenant.diario-obra.rdo.flow', [$tenant, $rdo]), [
                'action' => 'approve',
                'comment' => 'Aprovado pela gerenciadora.',
            ])
            ->assertSessionHasNoErrors();
        $this->assertSame('em_aprovacao', $rdo->fresh()->status);

        $this->actingAs($user)
            ->post(route('tenant.diario-obra.rdo.flow', [$tenant, $rdo]), [
                'action' => 'approve',
                'comment' => 'De acordo.',
            ])
            ->assertSessionHasNoErrors();

        $rdo->refresh();
        $this->assertSame('arquivado', $rdo->status);
        $this->assertNotNull($rdo->approved_at);
        $this->assertDatabaseCount('rdo_analises', 3);
        $this->assertDatabaseHas('rdo_analises', [
            'rdo_diario_id' => $rdo->id,
            'etapa' => 'gerenciadora',
            'decisao' => 'approve',
        ]);
    }

    public function test_joint_rdo_approval_with_any_reservation_requires_constructor_evidence(): void
    {
        [$tenant, $user, $contract, $obra] = $this->scenario();
        $configuration = $this->configuration($tenant->id, $contract->id, $obra->id, $user->id);
        $configuration->obras()->attach($obra->id);
        $rdo = app(RdoDailyGenerator::class)->generateForConfiguration(
            $configuration,
            CarbonImmutable::parse('2026-06-25'),
            false,
            $user->id,
        );
        $rdo->update(['status' => 'em_aprovacao']);

        $this->actingAs($user)
            ->post(route('tenant.diario-obra.rdo.flow', [$tenant, $rdo]), [
                'action' => 'approve_with_reservations',
                'comment' => 'Gerenciadora solicitou comprovação fotográfica.',
            ])
            ->assertSessionHasNoErrors();
        $this->assertSame('em_aprovacao', $rdo->fresh()->status);

        $this->actingAs($user)
            ->post(route('tenant.diario-obra.rdo.flow', [$tenant, $rdo]), [
                'action' => 'approve',
                'comment' => 'Cliente de acordo.',
            ])
            ->assertSessionHasNoErrors();
        $this->assertSame('pendente_comprovacao', $rdo->fresh()->status);
    }

    public function test_returned_rdo_requires_constructor_response_before_resubmission(): void
    {
        [$tenant, $user, $contract, $obra] = $this->scenario();
        $configuration = $this->configuration($tenant->id, $contract->id, $obra->id, $user->id);
        $configuration->obras()->attach($obra->id);
        $rdo = app(RdoDailyGenerator::class)->generateForConfiguration(
            $configuration,
            CarbonImmutable::parse('2026-06-25'),
            false,
            $user->id,
        );
        $rdo->update(['status' => 'em_aprovacao']);

        $this->actingAs($user)
            ->post(route('tenant.diario-obra.rdo.flow', [$tenant, $rdo]), [
                'action' => 'return',
                'comment' => 'Corrigir o efetivo informado.',
            ])
            ->assertSessionHasNoErrors();
        $this->assertSame('devolvido_construtora', $rdo->fresh()->status);

        $this->actingAs($user)
            ->post(route('tenant.diario-obra.rdo.flow', [$tenant, $rdo]), [
                'action' => 'submit',
                'comment' => '',
            ])
            ->assertStatus(422);
    }

    public function test_rdo_submission_notifies_manager_users_by_system_and_email_notification(): void
    {
        Notification::fake();
        [$tenant, $user, $contract, $obra] = $this->scenario();
        $managerCompany = $tenant->empresas()->create([
            'contract_id' => $contract->id,
            'tipo_empresa_id' => DB::table('tipos_empresa')->where('nome', 'gerenciadora')->value('id'),
            'nome' => 'Gerenciadora do contrato',
            'cnpj' => '12.345.678/0001-91',
            'sigla' => 'GER',
        ]);
        $contract->update(['fiscalizadora_empresa_id' => $managerCompany->id]);
        $manager = User::factory()->create();
        $tenant->memberships()->create([
            'user_id' => $manager->id,
            'empresa_id' => $managerCompany->id,
            'role' => 'tenant_member',
            'status' => 'active',
        ]);
        $configuration = $this->configuration($tenant->id, $contract->id, $obra->id, $user->id);
        $configuration->obras()->attach($obra->id);
        $rdo = app(RdoDailyGenerator::class)->generateForConfiguration(
            $configuration,
            CarbonImmutable::parse('2026-06-25'),
            false,
            $user->id,
        );
        foreach (['clima', 'mao_obra', 'equipamentos', 'atividades', 'fotos', 'comentarios'] as $section) {
            $rdo->secoes()->create([
                'tenant_id' => $tenant->id,
                'obra_id' => $obra->id,
                'updated_by_id' => $user->id,
                'secao' => $section,
                'dados' => [],
            ]);
        }

        $this->actingAs($user)
            ->post(route('tenant.diario-obra.rdo.flow', [$tenant, $rdo]), [
                'action' => 'submit',
                'comment' => 'RDO pronto para análise.',
            ])
            ->assertSessionHasNoErrors();

        Notification::assertSentTo($manager, RdoFlowChangedNotification::class);
    }

    public function test_rdo_responsibilities_are_scoped_by_front_and_each_stage_waits_for_all_fronts(): void
    {
        Notification::fake();
        [$tenant, $owner, $contract, $obra] = $this->scenario();
        $secondObra = $contract->obras()->create([
            'tenant_id' => $tenant->id,
            'obra_pai_id' => $obra->id,
            'codigo' => '101',
            'nome' => 'Frente 02',
            'tipo' => 'filha',
        ]);
        $types = DB::table('tipos_empresa')->whereIn('nome', ['construtora', 'gerenciadora', 'cliente'])->pluck('id', 'nome');
        $constructorCompany = $tenant->empresas()->create([
            'contract_id' => $contract->id,
            'tipo_empresa_id' => $types['construtora'],
            'nome' => 'Construtora RDO',
            'cnpj' => '12.345.678/0001-92',
            'sigla' => 'CTR',
        ]);
        $managerCompany = $tenant->empresas()->create([
            'contract_id' => $contract->id,
            'tipo_empresa_id' => $types['gerenciadora'],
            'nome' => 'Gerenciadora RDO',
            'cnpj' => '12.345.678/0001-93',
            'sigla' => 'GER',
        ]);
        $clientCompany = $tenant->empresas()->create([
            'contract_id' => $contract->id,
            'tipo_empresa_id' => $types['cliente'],
            'nome' => 'Cliente RDO',
            'cnpj' => '12.345.678/0001-94',
            'sigla' => 'CLI',
        ]);
        $contract->update([
            'construtora_empresa_id' => $constructorCompany->id,
            'fiscalizadora_empresa_id' => $managerCompany->id,
            'cliente_empresa_id' => $clientCompany->id,
        ]);

        $constructorOne = User::factory()->create();
        $constructorTwo = User::factory()->create();
        $manager = User::factory()->create();
        $client = User::factory()->create();
        foreach ([
            [$constructorOne, $constructorCompany],
            [$constructorTwo, $constructorCompany],
            [$manager, $managerCompany],
            [$client, $clientCompany],
        ] as [$user, $company]) {
            $tenant->memberships()->create([
                'user_id' => $user->id,
                'empresa_id' => $company->id,
                'role' => 'tenant_member',
                'status' => 'active',
            ]);
        }

        $configuration = $this->configuration($tenant->id, $contract->id, $obra->id, $owner->id);
        $configuration->obras()->attach([$obra->id, $secondObra->id]);
        $rdo = app(RdoDailyGenerator::class)->generateForConfiguration(
            $configuration,
            CarbonImmutable::parse('2026-06-25'),
            false,
            $owner->id,
        );
        foreach ([$obra, $secondObra] as $front) {
            foreach (['clima', 'mao_obra', 'equipamentos', 'atividades', 'fotos', 'comentarios'] as $section) {
                $rdo->secoes()->create([
                    'tenant_id' => $tenant->id,
                    'obra_id' => $front->id,
                    'updated_by_id' => $owner->id,
                    'secao' => $section,
                    'dados' => [],
                ]);
            }
        }
        foreach ([
            [$obra, $constructorOne, 'construtora'],
            [$secondObra, $constructorTwo, 'construtora'],
            [$obra, $manager, 'gerenciadora'],
            [$secondObra, $manager, 'gerenciadora'],
            [$obra, $client, 'cliente'],
            [$secondObra, $client, 'cliente'],
        ] as [$front, $user, $stage]) {
            RdoResponsavel::create([
                'tenant_id' => $tenant->id,
                'contract_id' => $contract->id,
                'obra_id' => $front->id,
                'user_id' => $user->id,
                'created_by_id' => $owner->id,
                'etapa' => $stage,
                'status' => 'active',
            ]);
        }

        $this->actingAs($constructorOne)
            ->post(route('tenant.diario-obra.rdo.flow', [$tenant, $rdo]), ['action' => 'submit'])
            ->assertSessionHasNoErrors();
        $this->assertSame('em_aprovacao', $rdo->fresh()->status);
        $this->assertDatabaseHas('rdo_analises', ['rdo_diario_id' => $rdo->id, 'obra_id' => $obra->id, 'etapa' => 'construtora']);
        $this->assertDatabaseHas('rdo_analises', ['rdo_diario_id' => $rdo->id, 'obra_id' => $secondObra->id, 'etapa' => 'construtora']);

        $this->actingAs($manager)
            ->post(route('tenant.diario-obra.rdo.flow', [$tenant, $rdo]), ['action' => 'approve'])
            ->assertSessionHasNoErrors();
        $this->assertSame('em_aprovacao', $rdo->fresh()->status);

        $this->actingAs($client)
            ->post(route('tenant.diario-obra.rdo.flow', [$tenant, $rdo]), ['action' => 'approve'])
            ->assertSessionHasNoErrors();
        $this->assertSame('arquivado', $rdo->fresh()->status);
    }

    public function test_archived_rdo_can_create_local_signature_request(): void
    {
        config(['signatures.driver' => 'local']);
        Storage::fake('public');
        Notification::fake();

        [$tenant, $user, $contract, $obra] = $this->scenario();
        $configuration = $this->configuration($tenant->id, $contract->id, $obra->id, $user->id);
        $rdo = app(RdoDailyGenerator::class)->generateForConfiguration(
            $configuration,
            CarbonImmutable::parse('2026-06-25'),
            false,
            $user->id,
        );
        $rdo->update(['status' => 'arquivado', 'approved_at' => now()]);

        foreach (['construtora', 'gerenciadora', 'cliente'] as $stage) {
            RdoResponsavel::create([
                'tenant_id' => $tenant->id,
                'contract_id' => $contract->id,
                'obra_id' => $obra->id,
                'user_id' => $user->id,
                'created_by_id' => $user->id,
                'etapa' => $stage,
                'status' => 'active',
            ]);
        }

        $this->actingAs($user)
            ->post(route('tenant.diario-obra.rdo.signatures.store', [$tenant, $rdo]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('rdo_signature_requests', [
            'tenant_id' => $tenant->id,
            'rdo_diario_id' => $rdo->id,
            'provider' => 'local',
            'status' => 'sent',
        ]);
        $this->assertDatabaseCount('rdo_signature_signers', 3);
    }

    public function test_rdo_signature_uses_signature_responsibles_and_notifies_them(): void
    {
        config(['signatures.driver' => 'local']);
        Storage::fake('public');
        Notification::fake();

        [$tenant, $user, $contract, $obra] = $this->scenario();
        $signer = User::factory()->create(['email' => 'assinador@rdo.test']);
        $tenant->memberships()->create([
            'user_id' => $signer->id,
            'role' => 'member',
            'status' => 'active',
        ]);
        $configuration = $this->configuration($tenant->id, $contract->id, $obra->id, $user->id);
        $rdo = app(RdoDailyGenerator::class)->generateForConfiguration(
            $configuration,
            CarbonImmutable::parse('2026-06-25'),
            false,
            $user->id,
        );
        $rdo->update(['status' => 'arquivado', 'approved_at' => now()]);

        RdoResponsavel::create([
            'tenant_id' => $tenant->id,
            'contract_id' => $contract->id,
            'obra_id' => $obra->id,
            'user_id' => $signer->id,
            'created_by_id' => $user->id,
            'etapa' => 'assinatura',
            'status' => 'active',
        ]);

        $this->actingAs($user)
            ->post(route('tenant.diario-obra.rdo.signatures.store', [$tenant, $rdo]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('rdo_signature_signers', [
            'tenant_id' => $tenant->id,
            'user_id' => $signer->id,
            'role' => 'assinatura',
            'email' => 'assinador@rdo.test',
        ]);
        $this->assertDatabaseCount('rdo_signature_signers', 1);
        Notification::assertSentTo($signer, \App\Notifications\RdoSignatureRequestedNotification::class);
    }

    public function test_rdo_signature_sends_opensign_v12_json_payload(): void
    {
        config([
            'signatures.driver' => 'opensign',
            'signatures.opensign.base_url' => 'https://sandbox.opensign.test/api/v1.2',
            'signatures.opensign.api_key' => 'test-api-key',
            'signatures.opensign.create_request_path' => '/createdocument',
        ]);
        Storage::fake('public');
        Notification::fake();
        [$tenant, $user, $contract, $obra] = $this->scenario();

        Http::fake([
            'https://sandbox.opensign.test/api/v1.2/createdocument' => Http::response([
                'document_id' => 'doc-rdo-123',
                'url' => 'https://sandbox.opensign.test/documents/doc-rdo-123',
            ]),
            'https://sandbox.opensign.test/api/v1.2/signinglinks/doc-rdo-123' => Http::response([
                'signinglinks' => [[
                    'email' => $user->email,
                    'url' => 'https://sandbox.opensign.test/sign/signer-rdo-123',
                    'objectId' => 'signer-rdo-123',
                ]],
            ]),
        ]);

        $configuration = $this->configuration($tenant->id, $contract->id, $obra->id, $user->id);
        $rdo = app(RdoDailyGenerator::class)->generateForConfiguration(
            $configuration,
            CarbonImmutable::parse('2026-06-25'),
            false,
            $user->id,
        );
        $rdo->update(['status' => 'arquivado', 'approved_at' => now()]);

        RdoResponsavel::create([
            'tenant_id' => $tenant->id,
            'contract_id' => $contract->id,
            'obra_id' => $obra->id,
            'user_id' => $user->id,
            'created_by_id' => $user->id,
            'etapa' => 'assinatura',
            'status' => 'active',
        ]);

        $this->actingAs($user)
            ->post(route('tenant.diario-obra.rdo.signatures.store', [$tenant, $rdo]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        Http::assertSent(function ($request): bool {
            $payload = $request->data();

            return $request->url() === 'https://sandbox.opensign.test/api/v1.2/createdocument'
                && $request->hasHeader('x-api-token', 'test-api-key')
                && str_contains((string) $request->header('Content-Type')[0], 'application/json')
                && base64_decode((string) ($payload['file'] ?? ''), true) !== false
                && ($payload['send_email'] ?? null) === true
                && ($payload['hide_signer_signing_links'] ?? null) === false
                && str_contains((string) ($payload['email_body'] ?? ''), '{{signing_url}}')
                && data_get($payload, 'signers.0.signer_role') === 'signer'
                && data_get($payload, 'signers.0.widgets.0.type') === 'signature';
        });

        Http::assertSent(fn ($request): bool => $request->method() === 'GET'
            && $request->url() === 'https://sandbox.opensign.test/api/v1.2/signinglinks/doc-rdo-123'
            && $request->hasHeader('x-api-token', 'test-api-key'));

        $this->assertDatabaseHas('rdo_signature_requests', [
            'rdo_diario_id' => $rdo->id,
            'provider' => 'opensign',
            'provider_document_id' => 'doc-rdo-123',
            'status' => 'sent',
        ]);
        $this->assertDatabaseHas('rdo_signature_signers', [
            'rdo_signature_request_id' => RdoSignatureRequest::query()->latest('id')->value('id'),
            'email' => $user->email,
            'provider_signer_id' => 'signer-rdo-123',
            'signing_url' => 'https://sandbox.opensign.test/sign/signer-rdo-123',
        ]);

        Notification::assertSentTo(
            $user,
            \App\Notifications\RdoSignatureRequestedNotification::class,
            fn ($notification): bool => $notification->signingUrl === 'https://sandbox.opensign.test/sign/signer-rdo-123'
        );

        $signatureRequest = RdoSignatureRequest::query()->with('rdo')->latest('id')->firstOrFail();
        $mail = (new \App\Notifications\RdoSignatureRequestedNotification(
            $signatureRequest,
            $tenant,
            'https://sandbox.opensign.test/sign/signer-rdo-123',
        ))->toMail($user);

        $this->assertSame('emails.rdo-signature-requested', data_get($mail->view, 'html'));
        $this->assertSame('emails.rdo-signature-requested-text', data_get($mail->view, 'text'));
        $this->assertSame(
            'https://sandbox.opensign.test/sign/signer-rdo-123',
            data_get($mail->viewData, 'signingUrl')
        );
    }

    private function scenario(): array
    {
        $tenant = Tenant::create([
            'slug' => 'rdo-teste',
            'name' => 'Empresa RDO',
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
            'code' => 'CT-RDO',
            'name' => 'Contrato RDO',
            'status' => 'active',
        ]);
        $obra = $contract->obras()->create([
            'tenant_id' => $tenant->id,
            'codigo' => '100',
            'nome' => 'Obra RDO',
            'tipo' => 'pai',
        ]);

        return [$tenant, $user, $contract, $obra];
    }

    private function configuration(int $tenantId, int $contractId, int $obraId, int $userId, array $overrides = []): RdoConfiguracao
    {
        return RdoConfiguracao::create([
            'tenant_id' => $tenantId,
            'contract_id' => $contractId,
            'obra_id' => $obraId,
            'responsible_user_id' => $userId,
            'created_by_id' => $userId,
            'start_date' => '2026-01-01',
            'generation_time' => '00:00',
            'timezone' => 'America/Sao_Paulo',
            'generation_weekdays' => [0, 1, 2, 3, 4, 5, 6],
            'generate_on_holidays' => true,
            'copy_previous_day' => false,
            'copy_workforce' => true,
            'copy_equipment' => true,
            'copy_pending_activities' => true,
            'require_photos' => false,
            'submission_deadline_days' => 7,
            'active' => true,
            ...$overrides,
        ]);
    }
}
