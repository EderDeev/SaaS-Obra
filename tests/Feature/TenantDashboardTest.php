<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\BoletimMedicao;
use App\Models\ContractAdditive;
use App\Models\ContractParticipant;
use App\Models\OrdemServico;
use App\Models\RdoConfiguracao;
use App\Models\RdoDiario;
use App\Models\Tenant;
use App\Models\User;
use App\Support\ContractPermissions;
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
            'status' => 'em_aprovacao',
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
