<?php

namespace Tests\Feature;

use App\Models\{Activity, Contract, ContractParticipant, Disciplina, FolhaRosto, MedicaoItem, Orcamento, OsDnitOrder, ProjectDocument, RdoDiario, RelatorioNaoConformidade, Tenant, TipoEmpresa, User};
use App\Support\{BudgetPermissions, ContractModuleSummary, ContractPermissions, MedicaoPermissions};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ContractModuleSummaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_modules_follow_records_of_each_contract_including_finished_records(): void
    {
        $tenant = Tenant::create(['slug' => 'module-contracts', 'name' => 'Diretoria', 'status' => 'active', 'plan' => 'starter']);
        $admin = User::factory()->create(['is_platform_admin' => true, 'os_dnit_contract_exemption' => true]);
        $contracts = collect(['Vazio', 'RNC', 'RNC e RDO', 'OS e Atividades'])->map(fn ($name, $i) => $tenant->contracts()->create(['code' => 'MODULE-'.$i, 'name' => $name, 'status' => 'active']));
        $this->rnc($tenant, $contracts[1], $admin);
        $work = $this->rnc($tenant, $contracts[2], $admin)->obra;
        $configuration = \App\Models\RdoConfiguracao::create(['tenant_id' => $tenant->id, 'contract_id' => $contracts[2]->id, 'obra_id' => $work->id, 'start_date' => today(), 'generation_weekdays' => [0, 1, 2, 3, 4, 5, 6]]);
        RdoDiario::create(['tenant_id' => $tenant->id, 'rdo_configuracao_id' => $configuration->id, 'contract_id' => $contracts[2]->id, 'obra_id' => $work->id, 'sequence_number' => 1, 'code' => 'RDO-001', 'reference_date' => today(), 'status' => 'arquivado']);
        Activity::create(['tenant_id' => $tenant->id, 'contract_id' => $contracts[3]->id, 'created_by_id' => $admin->id, 'title' => 'Atividade concluída', 'status' => 'done', 'visibility' => 'public', 'category' => 'project', 'priority' => 'medium']);
        $road = \App\Models\OsDnitHighway::create(['tenant_id' => $tenant->id, 'contract_id' => $contracts[3]->id, 'name' => 'BR-316', 'active' => true]);
        OsDnitOrder::create(['tenant_id' => $tenant->id, 'contract_id' => $contracts[3]->id, 'highway_id' => $road->id, 'created_by_id' => $admin->id, 'request_number' => '001/2026', 'supervision_lot' => 'Lote 01', 'highway_name' => 'BR-316', 'km_start' => 0, 'km_end' => 1, 'subject' => 'Serviço concluído', 'desired_deadline' => today(), 'technical_need' => 'Inspeção', 'justification' => 'Acompanhamento', 'status' => 'completed']);

        foreach ([[], ['rncs'], ['rncs', 'rdos'], ['activities', 'os']] as $index => $expected) {
            $this->actingAs($admin)->get(route('tenant.contracts.show', [$tenant, $contracts[$index]]))->assertOk()
                ->assertInertia(fn (Assert $page) => $page->where('modules', function ($modules) use ($expected) {
                    $actual = array_keys($modules->toArray());
                    sort($actual);
                    sort($expected);
                    $this->assertSame($expected, $actual);
                    return true;
                }));
        }
        foreach (ContractModuleSummary::forUser($admin, $tenant, $contracts[3]) as $module) {
            $this->assertTrue(\Illuminate\Support\Facades\Route::has($module['route']));
        }
        $contracts[3]->activities()->firstOrFail()->delete();
        $contracts[3]->projectDocuments()->create(['tenant_id' => $tenant->id, 'title' => 'Projeto excluído', 'status' => 'ativo'])->delete();
        $this->assertSame(['os'], array_keys(ContractModuleSummary::forUser($admin, $tenant, $contracts[3])));
    }

    public function test_hidden_or_unauthorized_records_do_not_expose_modules_or_recent_rows(): void
    {
        $tenant = Tenant::create(['slug' => 'module-permissions', 'name' => 'Diretoria', 'status' => 'active', 'plan' => 'starter']);
        $contract = $tenant->contracts()->create(['code' => 'PRIVATE', 'name' => 'Privado', 'status' => 'active']);
        $admin = User::factory()->create(['is_platform_admin' => true]);
        $user = User::factory()->create();
        $tenant->memberships()->create(['user_id' => $user->id, 'role' => 'member', 'status' => 'active']);
        ContractParticipant::create(['tenant_id' => $tenant->id, 'contract_id' => $contract->id, 'user_id' => $user->id, 'side' => 'manager', 'role' => 'team_member', 'status' => 'active', 'contract_permissions' => [ContractPermissions::VIEW], 'activity_permissions' => [], 'rnc_permissions' => [], 'project_permissions' => []]);
        $this->rnc($tenant, $contract, $admin);
        ProjectDocument::create(['tenant_id' => $tenant->id, 'contract_id' => $contract->id, 'title' => 'Projeto privado']);
        Activity::create(['tenant_id' => $tenant->id, 'contract_id' => $contract->id, 'created_by_id' => $admin->id, 'title' => 'Atividade restrita', 'status' => 'todo', 'visibility' => 'restricted', 'category' => 'project', 'priority' => 'medium']);
        $this->assertSame([], ContractModuleSummary::forUser($user, $tenant, $contract));
        $this->actingAs($user)->get(route('tenant.contracts.show', [$tenant, $contract]))->assertOk()->assertInertia(fn (Assert $page) => $page->has('modules', 0)->has('recentActivities', 0)->has('recentRncs', 0)->has('recentProjects', 0));
        $this->assertArrayNotHasKey('activities', ContractModuleSummary::forUser(User::factory()->create(['is_platform_admin' => true]), $tenant, $contract));
    }

    public function test_rdo_summary_tracks_workflow_and_only_the_latest_signature_attempt_per_document(): void
    {
        $tenant = Tenant::create(['slug' => 'rdo-summary', 'name' => 'Diretoria', 'status' => 'active', 'plan' => 'starter']);
        $admin = User::factory()->create(['is_platform_admin' => true]);
        $contract = $tenant->contracts()->create(['code' => 'RDO', 'name' => 'Contrato RDO', 'status' => 'active']);
        $work = $tenant->obras()->create(['contract_id' => $contract->id, 'codigo' => 'OB', 'nome' => 'Obra', 'tipo' => 'pai']);
        $configuration = \App\Models\RdoConfiguracao::create(['tenant_id' => $tenant->id, 'contract_id' => $contract->id, 'obra_id' => $work->id, 'start_date' => today(), 'generation_weekdays' => [1]]);
        $diaries = collect(['rascunho', 'em_analise_gerenciadora', 'em_aprovacao_cliente', 'devolvido_construtora', 'pendente_comprovacao', 'arquivado', 'arquivado', 'arquivado'])
            ->map(fn ($status, $i) => RdoDiario::create(['tenant_id' => $tenant->id, 'contract_id' => $contract->id, 'obra_id' => $work->id, 'rdo_configuracao_id' => $configuration->id, 'sequence_number' => $i + 1, 'code' => 'RDO-'.($i + 1), 'reference_date' => today()->subDays($i), 'status' => $status]));
        $request = fn ($rdo, $status) => \App\Models\RdoSignatureRequest::create(['tenant_id' => $tenant->id, 'rdo_diario_id' => $rdo->id, 'requested_by_id' => $admin->id, 'provider' => 'local', 'status' => $status, 'title' => 'Assinatura RDO']);
        $request($diaries[5], 'failed');
        $request($diaries[5], 'sent');
        $request($diaries[6], 'sent');
        $request($diaries[6], 'completed');
        $request($diaries[7], 'failed');
        // Deleted diaries and another contract must not affect the summary.
        $diaries[0]->replicate()->fill(['sequence_number' => 20, 'code' => 'RDO-20', 'reference_date' => today()->subDays(20)])->save();
        RdoDiario::where('code', 'RDO-20')->firstOrFail()->delete();
        $otherContract = $tenant->contracts()->create(['code' => 'OUTRO', 'name' => 'Outro contrato', 'status' => 'active']);
        $diaries[0]->replicate()->fill(['contract_id' => $otherContract->id, 'sequence_number' => 21, 'code' => 'RDO-21'])->save();

        $summary = ContractModuleSummary::forUser($admin, $tenant, $contract)['rdos'];
        $metrics = collect($summary['metrics'])->pluck('value', 'label');
        $this->assertSame(8, $summary['count']);
        $this->assertSame(5, $summary['pending']);
        foreach (['Em preenchimento', 'Análise da gerenciadora', 'Aguardando cliente', 'Devolvidos para ajustes', 'Comprovações pendentes', 'Em assinatura', 'Falhas no envio para assinatura'] as $label) {
            $this->assertSame(1, $metrics[$label], $label);
        }
        $this->assertSame(3, $metrics['Aprovados']);
        $this->assertStringContainsString('falha', $summary['attention']);
        $this->assertSame(today()->toDateString(), substr($summary['latest_date'], 0, 10));
    }

    public function test_manager_only_rdos_do_not_show_nonexistent_review_stages(): void
    {
        $tenant = Tenant::create(['slug' => 'manager-summary', 'name' => 'Diretoria', 'status' => 'active', 'plan' => 'starter']);
        $admin = User::factory()->create(['is_platform_admin' => true]);
        $contract = $tenant->contracts()->create(['code' => 'MANAGER', 'name' => 'Gerenciadora', 'status' => 'active']);
        $work = $tenant->obras()->create(['contract_id' => $contract->id, 'codigo' => 'OB', 'nome' => 'Obra', 'tipo' => 'pai']);
        $configuration = \App\Models\RdoConfiguracao::create(['tenant_id' => $tenant->id, 'contract_id' => $contract->id, 'obra_id' => $work->id, 'start_date' => today(), 'generation_weekdays' => [1]]);
        foreach (['rascunho', 'arquivado'] as $i => $status) {
            RdoDiario::create(['tenant_id' => $tenant->id, 'contract_id' => $contract->id, 'obra_id' => $work->id, 'rdo_configuracao_id' => $configuration->id, 'sequence_number' => $i + 1, 'code' => 'RDO-'.($i + 1), 'reference_date' => today()->subDays($i), 'status' => $status, 'workflow_snapshot' => \App\Support\RdoWorkflow::forModel('gerenciadora')]);
        }
        $summary = ContractModuleSummary::forUser($admin, $tenant, $contract)['rdos'];
        $this->assertSame(1, $summary['pending']);
        $this->assertSame(['Em preenchimento', 'Salvos sem fluxo de aprovação'], array_column($summary['metrics'], 'label'));
        $this->assertNull($summary['attention']);
    }

    public function test_budget_and_measurement_cards_follow_contract_records_and_permissions(): void
    {
        $tenant = Tenant::create(['slug' => 'contract-measurements', 'name' => 'Diretoria', 'status' => 'active', 'plan' => 'starter']);
        $admin = User::factory()->create(['is_platform_admin' => true]);
        $member = User::factory()->create();
        $contract = $tenant->contracts()->create(['code' => 'A', 'name' => 'Contrato A', 'status' => 'active']);
        $other = $tenant->contracts()->create(['code' => 'B', 'name' => 'Contrato B', 'status' => 'active']);
        $tenant->memberships()->create(['user_id' => $member->id, 'role' => 'member', 'status' => 'active', 'budget_permissions' => [BudgetPermissions::VIEW]]);
        ContractParticipant::create([
            'tenant_id' => $tenant->id, 'contract_id' => $contract->id, 'user_id' => $member->id,
            'side' => 'manager', 'role' => 'team_member', 'status' => 'active',
            'contract_permissions' => [ContractPermissions::VIEW], 'medicao_permissions' => [MedicaoPermissions::VIEW],
        ]);
        $budget = Orcamento::create(['tenant_id' => $tenant->id, 'created_by_id' => $admin->id, 'codigo' => 'ORC-A', 'descricao' => 'Base A', 'categoria' => 'Pavimentação', 'status' => 'closed']);
        $unrelated = Orcamento::create(['tenant_id' => $tenant->id, 'created_by_id' => $admin->id, 'codigo' => 'ORC-B', 'descricao' => 'Base B', 'categoria' => 'Pavimentação', 'status' => 'closed']);
        foreach ([[$contract, $budget], [$contract, $budget], [$other, $unrelated]] as [$context, $source]) {
            MedicaoItem::create(['tenant_id' => $tenant->id, 'contract_id' => $context->id, 'source_orcamento_id' => $source->id, 'source_type' => 'orcamento', 'descricao' => 'Pavimentação']);
        }

        $this->actingAs($admin)->get(route('tenant.contracts.show', [$tenant, $contract]))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('modules.measurements.pending', 2)
                ->where('modules.measurements.route', 'tenant.medicao.item.index')->where('modules.budgets.count', 1));
        $work = $contract->obras()->create(['tenant_id' => $tenant->id, 'codigo' => '01', 'nome' => 'Frente', 'tipo' => 'pai']);
        FolhaRosto::create(['tenant_id' => $tenant->id, 'contract_id' => $contract->id, 'obra_id' => $work->id, 'codigo' => 'FR-001', 'sequencial' => 1, 'status' => 'analise_fiscal', 'comentario' => 'Medição de pavimentação']);
        $this->actingAs($member)->get(route('tenant.contracts.show', [$tenant, $contract]))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->missing('modules.budgets')->where('modules.measurements.pending', 1)
                ->where('modules.measurements.route', 'tenant.medicao.folha-rosto.index')
                ->where('modules.measurements.metrics.0.label', 'Folhas de rosto em análise'));

        $filteredList = route('tenant.orcamentos.index', ['tenant' => $tenant, 'contract_id' => $contract->id]);
        $this->actingAs($admin)->get($filteredList)->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('filteredContract.code', 'A')
                ->has('orcamentos', 1)->where('orcamentos.0.id', $budget->id));
        $this->actingAs($member)->get($filteredList)->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('orcamentos', 0));
        $this->get(route('tenant.orcamentos.index', ['tenant' => $tenant, 'contract_id' => $other->id]))->assertForbidden();
    }

    private function rnc(Tenant $tenant, Contract $contract, User $user): RelatorioNaoConformidade
    {
        $work = $tenant->obras()->create(['contract_id' => $contract->id, 'codigo' => 'OB', 'nome' => 'Obra', 'tipo' => 'pai']);
        $discipline = Disciplina::create(['tenant_id' => $tenant->id, 'contract_id' => $contract->id, 'nome' => 'Qualidade', 'sigla' => 'QUA', 'cor' => '#000000']);
        $company = $tenant->empresas()->create(['contract_id' => $contract->id, 'nome' => 'Empresa', 'sigla' => 'EMP', 'cnpj' => str_pad((string) $contract->id, 14, '0', STR_PAD_LEFT), 'tipo_empresa_id' => TipoEmpresa::firstOrCreate(['nome' => 'gerenciadora'])->id]);
        return RelatorioNaoConformidade::create(['tenant_id' => $tenant->id, 'contract_id' => $contract->id, 'obra_id' => $work->id, 'contratante_empresa_id' => $company->id, 'contratada_empresa_id' => $company->id, 'created_by_id' => $user->id, 'disciplina_id' => $discipline->id, 'sequence_number' => $contract->id, 'sequence_year' => 2026, 'opened_at' => today(), 'natureza' => 'Qualidade', 'gravidade' => 'Média', 'assunto' => 'Inspeção', 'descricao_problema' => 'Não conformidade', 'acoes_corretivas_recomendadas' => 'Corrigir', 'prazo_resposta_acao_corretiva' => today()->addWeek(), 'status' => 'finalizada']);
    }
}
