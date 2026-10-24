<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\BoletimMedicao;
use App\Models\ContractAdditive;
use App\Models\ContractParticipant;
use App\Models\OrdemServico;
use App\Models\OsDnitHighway;
use App\Models\OsDnitOrder;
use App\Models\ProjectDocument;
use App\Models\Empresa;
use App\Models\TipoEmpresa;
use App\Models\RelatorioNaoConformidade;
use App\Models\RdoConfiguracao;
use App\Models\RdoDiario;
use App\Models\Tenant;
use App\Models\User;
use App\Support\ContractPermissions;
use App\Support\ActivityPermissions;
use App\Support\OsDnitPermissions;
use App\Support\DiarioObraPermissions;
use App\Support\MedicaoPermissions;
use App\Support\OrdemServicoPermissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TenantDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_prioritizes_due_activities_and_presents_checklist_progress(): void
    {
        Carbon::setTestNow('2026-08-12 10:00:00');

        $tenant = Tenant::create([
            'slug' => 'dashboard-teste',
            'name' => 'Dashboard Teste',
            'plan' => 'starter',
            'status' => 'active',
        ]);
        $user = User::factory()->create();
        $tenant->memberships()->create([
            'user_id' => $user->id,
            'role' => 'tenant_admin',
            'status' => 'active',
        ]);
        $contract = $tenant->contracts()->create([
            'code' => 'CT-001',
            'name' => 'Contrato Teste',
            'status' => 'active',
        ]);

        $this->activity($tenant, $contract->id, $user->id, 'Atividade vencida', '2026-08-11');
        $checklist = $this->activity($tenant, $contract->id, $user->id, 'Checklist proximo', '2026-08-15', Activity::TYPE_CHECKLIST);
        $checklist->checklistItems()->createMany([
            ['label' => 'Etapa concluida', 'position' => 0, 'is_completed' => true],
            ['label' => 'Etapa pendente', 'position' => 1, 'is_completed' => false],
        ]);
        $this->activity($tenant, $contract->id, $user->id, 'Atividade distante', '2026-08-30');

        $this->actingAs($user)
            ->get(route('tenant.dashboard', $tenant))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Tenant/Dashboard')
                ->where('stats.overdueActivities', 1)
                ->where('stats.activitiesDueSoon', 1)
                ->has('attentionItems', 2)
                ->where('attentionItems.0.title', 'Atividade vencida')
                ->where('attentionItems.0.group', 'critical')
                ->where('attentionItems.1.title', 'Checklist proximo')
                ->where('attentionItems.1.group', 'due')
                ->where('myActivities.1.activity_type', Activity::TYPE_CHECKLIST)
                ->where('myActivities.1.checklist_items_count', 2)
                ->where('myActivities.1.completed_checklist_items_count', 1)
                ->missing('recentContracts')
            );
    }

    public function test_dashboard_filters_operational_modules_by_macro_permissions(): void
    {
        $tenant = Tenant::create([
            'slug' => 'dashboard-permissoes',
            'name' => 'Dashboard Permissoes',
            'plan' => 'starter',
            'status' => 'active',
        ]);
        $user = User::factory()->create();
        $tenant->memberships()->create([
            'user_id' => $user->id,
            'role' => 'engineer',
            'status' => 'active',
            'contract_permissions' => [],
            'diario_obra_permissions' => [],
            'medicao_permissions' => [],
            'ordem_servico_permissions' => [],
        ]);
        $contract = $tenant->contracts()->create([
            'code' => 'CT-PERM-001',
            'name' => 'Contrato com registros protegidos',
            'status' => 'active',
        ]);
        $participant = ContractParticipant::create([
            'tenant_id' => $tenant->id,
            'contract_id' => $contract->id,
            'user_id' => $user->id,
            'side' => 'manager',
            'role' => 'team_member',
            'status' => 'active',
            'contract_permissions' => [],
            'diario_obra_permissions' => [],
            'medicao_permissions' => [],
            'ordem_servico_permissions' => [],
        ]);
        $obra = $contract->obras()->create([
            'tenant_id' => $tenant->id,
            'codigo' => '001',
            'nome' => 'Obra principal',
            'tipo' => 'pai',
        ]);
        $configuracao = RdoConfiguracao::create([
            'tenant_id' => $tenant->id,
            'contract_id' => $contract->id,
            'obra_id' => $obra->id,
            'created_by_id' => $user->id,
            'start_date' => '2026-08-01',
            'generation_weekdays' => [1, 2, 3, 4, 5],
        ]);
        RdoDiario::create([
            'tenant_id' => $tenant->id,
            'rdo_configuracao_id' => $configuracao->id,
            'contract_id' => $contract->id,
            'obra_id' => $obra->id,
            'created_by_id' => $user->id,
            'sequence_number' => 1,
            'code' => 'RDO-001',
            'reference_date' => '2026-08-12',
            'status' => 'em_analise_gerenciadora',
        ]);
        BoletimMedicao::create([
            'tenant_id' => $tenant->id,
            'contract_id' => $contract->id,
            'created_by_id' => $user->id,
            'codigo' => 'BM-001',
            'sequencial' => 1,
            'periodo' => '2026-08-01',
            'tipo' => 'normal',
            'status' => 'aberto_lancamento',
        ]);
        OrdemServico::create([
            'tenant_id' => $tenant->id,
            'contract_id' => $contract->id,
            'obra_id' => $obra->id,
            'created_by_id' => $user->id,
            'codigo' => 'OS-001',
            'sequencial' => 1,
            'titulo' => 'OS protegida',
            'status' => 'em_analise',
        ]);
        ContractAdditive::create([
            'tenant_id' => $tenant->id,
            'contract_id' => $contract->id,
            'user_id' => $user->id,
            'sequence_number' => 1,
            'type' => 'cost',
            'title' => 'Aditivo protegido',
            'motivation' => 'Teste de permissao da visao geral.',
            'amount' => 100,
            'attachment_path' => 'tests/aditivo.pdf',
            'attachment_original_name' => 'aditivo.pdf',
            'attachment_mime_type' => 'application/pdf',
        ]);

        $this->actingAs($user)
            ->get(route('tenant.dashboard', $tenant))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('stats.rdoAwaitingReview', 0)
                ->where('stats.openBoletins', 0)
                ->where('stats.pendingOrders', 0)
                ->where('stats.additives', 0)
                ->where('capabilities.rdo', false)
                ->where('capabilities.measurements', false)
                ->where('capabilities.serviceOrders', false)
                ->where('capabilities.additives', false)
                ->where('attentionItems', [])
                ->where('recentEvents', [])
            );

        $participant->update([
            'contract_permissions' => [ContractPermissions::VIEW],
            'diario_obra_permissions' => [DiarioObraPermissions::VIEW],
            'medicao_permissions' => [MedicaoPermissions::VIEW],
            'ordem_servico_permissions' => [OrdemServicoPermissions::VIEW],
        ]);

        $this->actingAs($user)
            ->get(route('tenant.dashboard', $tenant))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('stats.rdoAwaitingReview', 1)
                ->where('stats.openBoletins', 1)
                ->where('stats.pendingOrders', 1)
                ->where('stats.additives', 1)
                ->where('capabilities.rdo', true)
                ->where('capabilities.measurements', true)
                ->where('capabilities.serviceOrders', true)
                ->where('capabilities.additives', true)
                ->has('attentionItems', 3)
                ->has('recentEvents', 4)
            );
    }

    public function test_contract_filter_updates_metrics_charts_events_personal_activities_and_os_summary(): void
    {
        $this->travelTo(Carbon::parse('2026-10-24 10:00:00'));
        $tenant = Tenant::create(['slug' => 'overview-filter', 'name' => 'Concremat Engenharia', 'plan' => 'starter', 'status' => 'active']);
        $user = User::factory()->create(['name' => 'Ana Martins']);
        $tenant->memberships()->create(['user_id' => $user->id, 'role' => 'tenant_owner', 'status' => 'active']);
        $contracts = [];
        foreach (['011/2021-NGTM', '001-DNIT'] as $index => $code) {
            $contract = $tenant->contracts()->create(['code' => $code, 'name' => $index ? 'Supervisão de obras rodoviárias' : 'Gestão e fiscalização de obras', 'status' => 'active']);
            $contracts[] = $contract;
            ContractParticipant::create(['tenant_id' => $tenant->id, 'contract_id' => $contract->id, 'user_id' => $user->id,
                'side' => 'manager', 'role' => 'manager', 'status' => 'active', 'os_dnit_permissions' => [OsDnitPermissions::VIEW]]);
            $road = OsDnitHighway::create(['tenant_id' => $tenant->id, 'contract_id' => $contract->id, 'name' => 'BR-364', 'active' => true]);
            OsDnitOrder::create(['tenant_id' => $tenant->id, 'contract_id' => $contract->id, 'highway_id' => $road->id,
                'created_by_id' => $user->id, 'request_number' => '001/2026', 'supervision_lot' => 'Lote 01', 'highway_name' => $road->name,
                'km_start' => 1, 'km_end' => 2, 'subject' => 'Levantamento de campo', 'desired_deadline' => '2026-11-30',
                'technical_need' => 'Verificar o trecho.', 'justification' => 'Acompanhar os serviços.', 'status' => $index ? 'approved' : 'awaiting_concremat']);
            ProjectDocument::create(['tenant_id' => $tenant->id, 'contract_id' => $contract->id, 'created_by_id' => $user->id,
                'title' => 'Projeto '.$code, 'document_type' => 'projeto', 'status' => $index ? 'em_aprovacao' : 'em_analise']);
            for ($month = 0; $month < 6; $month++) {
                for ($n = 0; $n < ($index ? 1 : 2); $n++) {
                    $activity = $this->activity($tenant, $contract->id, $user->id, 'Inspeção '.$month.' '.$n.' · '.$code, $month % 2 ? '2026-10-22' : '2026-10-28');
                    $activity->forceFill(['created_at' => now()->startOfMonth()->subMonths($month)->addDays(2), 'status' => $month >= 3 ? 'done' : 'todo'])->save();
                }
            }
        }
        $all = $this->actingAs($user)->get(route('tenant.dashboard', $tenant))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('contracts', 2)->where('stats.contracts', 2)->where('stats.openActivities', 9)
                ->where('stats.pendingProjects', 2)->where('osSummary.total', 2)
                ->where('charts.activityTrend', fn ($rows) => collect($rows)->sum('total') === 18));
        $filtered = $this->get(route('tenant.dashboard', [$tenant, 'contract_id' => $contracts[0]->id]))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('contracts', 2)->where('stats.contracts', 1)->where('stats.openActivities', 6)
                ->where('stats.pendingProjects', 1)->where('osSummary.total', 1)->where('osSummary.pending', 1)
                ->where('charts.activityTrend', fn ($rows) => collect($rows)->sum('total') === 12)
                ->where('myActivities', fn ($rows) => collect($rows)->every(fn ($row) => $row['contract_id'] === $contracts[0]->id))
                ->where('attentionItems', fn ($rows) => collect($rows)->every(fn ($row) => !str_contains($row['subtitle'], '001-DNIT')))
                ->where('recentEvents', fn ($rows) => collect($rows)->every(fn ($row) => !str_contains($row['subtitle'], '001-DNIT'))));
        $this->get(route('tenant.dashboard', $tenant))->assertInertia(fn (Assert $page) => $page->where('stats.contracts', 2)->where('osSummary.total', 2));
        if ($folder = getenv('OVERVIEW_CAPTURE_DIR')) {
            if (!is_dir($folder)) mkdir($folder, 0777, true);
            foreach (['all' => $all, 'filtered' => $filtered] as $name => $response) {
                file_put_contents($folder.'/'.$name.'.html', $response->getContent());
                file_put_contents($folder.'/'.$name.'.json', json_encode($response->viewData('page')));
            }
        }
    }

    public function test_contract_filter_rejects_foreign_and_unlinked_contracts_and_preserves_module_permissions(): void
    {
        $tenant = Tenant::create(['slug' => 'overview-restricted', 'name' => 'Restrito', 'plan' => 'starter', 'status' => 'active']);
        $user = User::factory()->create();
        $tenant->memberships()->create(['user_id' => $user->id, 'role' => 'engineer', 'status' => 'active', 'activity_permissions' => []]);
        $contracts = [];
        foreach (['A', 'B', 'C'] as $code) {
            $contract = $tenant->contracts()->create(['code' => $code, 'name' => $code, 'status' => 'active']);
            $contracts[$code] = $contract;
            if ($code !== 'C') ContractParticipant::create(['tenant_id' => $tenant->id, 'contract_id' => $contract->id, 'user_id' => $user->id,
                'side' => 'manager', 'role' => 'team_member', 'status' => 'active', 'activity_permissions' => $code === 'A' ? [ActivityPermissions::VIEW] : []]);
            $this->activity($tenant, $contract->id, $user->id, 'Atividade '.$code, '2026-11-01');
        }
        $url = fn ($id) => route('tenant.dashboard', [$tenant, 'contract_id' => $id]);
        $this->actingAs($user)->get($url($contracts['A']->id))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('contracts', 2)->where('stats.openActivities', 1)->where('capabilities.activities', true));
        $this->get($url($contracts['B']->id))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('stats.openActivities', 0)->where('capabilities.activities', false)->where('myActivities', [])->where('recentEvents', []));
        $this->get($url($contracts['C']->id))->assertSessionHasErrors('contract_id');
        $other = Tenant::create(['slug' => 'overview-foreign', 'name' => 'Outro', 'plan' => 'starter', 'status' => 'active']);
        $foreign = $other->contracts()->create(['code' => 'FOREIGN', 'name' => 'Outro', 'status' => 'active']);
        $this->get($url($foreign->id))->assertSessionHasErrors('contract_id');
        $this->get($url('invalid'))->assertSessionHasErrors('contract_id');
        $this->get(route('tenant.dashboard', $other))->assertForbidden();
    }

    public function test_overview_keeps_rncs_awaiting_signature_open_without_treating_them_as_overdue_responses(): void
    {
        $tenant = Tenant::create(['slug' => 'overview-signatures', 'name' => 'Assinaturas', 'plan' => 'starter', 'status' => 'active']);
        $user = User::factory()->create();
        $tenant->memberships()->create(['user_id' => $user->id, 'role' => 'tenant_owner', 'status' => 'active']);
        $contract = $tenant->contracts()->create(['code' => 'SIG', 'name' => 'Contrato', 'status' => 'active']);
        $obra = $contract->obras()->create(['tenant_id' => $tenant->id, 'codigo' => 'OB', 'nome' => 'Obra', 'tipo' => 'pai']);
        $company = Empresa::create(['tenant_id' => $tenant->id, 'contract_id' => $contract->id,
            'tipo_empresa_id' => TipoEmpresa::firstOrCreate(['nome' => 'gerenciadora'])->id, 'nome' => 'Empresa', 'sigla' => 'EMP', 'cnpj' => '11.111.111/0001-11']);
        foreach (['em_assinatura', 'finalizada', 'aberta'] as $i => $status) {
            RelatorioNaoConformidade::create(['tenant_id' => $tenant->id, 'contract_id' => $contract->id, 'obra_id' => $obra->id,
                'contratante_empresa_id' => $company->id, 'contratada_empresa_id' => $company->id, 'created_by_id' => $user->id,
                'sequence_number' => $i + 1, 'sequence_year' => 2026, 'opened_at' => today()->subDays(3), 'natureza' => 'Qualidade', 'gravidade' => 'Leve',
                'descricao_problema' => 'Problema', 'acoes_corretivas_recomendadas' => 'Corrigir', 'status' => $status,
                'prazo_resposta_acao_corretiva' => today()->subDay(), 'finalized_at' => $status === 'aberta' ? now() : null]);
        }
        $this->actingAs($user)->get(route('tenant.dashboard', [$tenant, 'contract_id' => $contract->id]))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('stats.openRncs', 1)->where('stats.overdueRncs', 0)
                ->where('charts.rncsByStatus', fn ($rows) => collect($rows)->firstWhere('key', 'em_assinatura')['value'] === 1
                    && collect($rows)->firstWhere('key', 'finalizada')['value'] === 2)
                ->where('attentionItems.0.badge', 'Aguardando assinaturas'));
    }

    private function activity(Tenant $tenant, int $contractId, int $userId, string $title, string $dueDate, string $type = Activity::TYPE_ACTIVITY): Activity
    {
        return $tenant->activities()->create([
            'contract_id' => $contractId,
            'created_by_id' => $userId,
            'assigned_to_id' => $userId,
            'activity_type' => $type,
            'title' => $title,
            'category' => 'field',
            'visibility' => Activity::VISIBILITY_PUBLIC,
            'status' => 'todo',
            'priority' => 'normal',
            'due_date' => $dueDate,
        ]);
    }
}
