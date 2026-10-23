<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\ContractParticipant;
use App\Models\OsDnitHighway;
use App\Models\OsDnitOrder;
use App\Models\Tenant;
use App\Models\User;
use App\Support\OsDnitPermissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class OsDnitManagementSummaryTest extends TestCase
{
    use RefreshDatabase;

    private int $sequence = 0;

    public function test_overview_and_contracts_show_the_same_accessible_stages_and_exclude_deleted_and_foreign_orders(): void
    {
        [$tenant, $user, $contract] = $this->context();
        foreach (array_keys(OsDnitOrder::STATUSES) as $status) {
            $this->order($contract, $user, $status);
        }
        $this->order($contract, $user, 'draft')->delete();
        $hidden = $this->contract($tenant);
        $this->order($hidden, $user, 'awaiting_contract');
        [$foreignTenant, $foreignUser, $foreignContract] = $this->context();
        $this->order($foreignContract, $foreignUser, 'approved');

        $this->actingAs($user)->get(route('tenant.dashboard', $tenant))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('osSummary.total', 7)
                ->where('osSummary.stages', ['analyst' => 2, 'concremat' => 2, 'contract_inspector' => 1, 'approved' => 1, 'completed' => 1])
                ->where('osSummary.pending', 3)->where('osSummary.returned', 2)->where('osSummary.overdue', 0)
                ->has('osSummary.recent', 4)
                ->where('osSummary.recent.0.status_label', 'Finalizada')
                ->where('osSummary.recent.0.contract', $contract->code));

        $this->get(route('tenant.contracts.show', [$tenant, $contract]))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('osSummary.total', 7)->where('osSummary.pending', 3));
        $this->get(route('tenant.contracts.show', [$tenant, $hidden]))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('osSummary', null));
        $this->get(route('tenant.contracts.index', $tenant))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('contracts', fn ($items) => collect($items)
                ->firstWhere('id', $contract->id)['os_summary'] === ['total' => 7, 'pending' => 3, 'completed' => 1]
                && collect($items)->firstWhere('id', $hidden->id)['os_summary'] === null));
        $this->get(route('tenant.dashboard', $foreignTenant))->assertForbidden();
    }

    public function test_view_permission_and_active_participation_are_required_even_for_tenant_admins(): void
    {
        [$tenant, $user, $contract, $participant] = $this->context();
        $this->order($contract, $user, 'awaiting_concremat');
        $participant->update(['os_dnit_permissions' => []]);
        $this->actingAs($user)->get(route('tenant.dashboard', $tenant))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('osSummary', null));
        $this->get(route('tenant.contracts.show', [$tenant, $contract]))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('osSummary', null));
        $this->get(route('tenant.contracts.index', $tenant))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('contracts.0.os_summary', null));

        $participant->update(['os_dnit_permissions' => [OsDnitPermissions::VIEW]]);
        $this->get(route('tenant.dashboard', $tenant))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('osSummary.total', 1));
        $participant->update(['status' => 'inactive']);
        $this->get(route('tenant.dashboard', $tenant))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('osSummary', null));
    }

    public function test_contract_summary_is_scoped_and_overdue_uses_supervision_deadline_and_excludes_drafts_and_completed(): void
    {
        [$tenant, $user, $contract] = $this->context();
        $this->travelTo(now()->setDate(2026, 10, 22)->startOfDay());
        $other = $this->contract($tenant);
        ContractParticipant::create(['tenant_id' => $tenant->id, 'contract_id' => $other->id, 'user_id' => $user->id,
            'side' => 'client', 'role' => 'manager', 'status' => 'active']);
        $this->order($contract, $user, 'approved', '2026-10-21');
        $this->order($contract, $user, 'awaiting_contract', '2026-10-21')->update(['estimated_deadline' => '2026-10-23']);
        $this->order($contract, $user, 'awaiting_concremat', '2026-10-22');
        $this->order($contract, $user, 'draft', '2026-10-21');
        $this->order($contract, $user, 'completed', '2026-10-21');
        $this->order($other, $user, 'awaiting_concremat', '2026-10-21');

        $this->actingAs($user)->get(route('tenant.dashboard', $tenant))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('osSummary.total', 6)->where('osSummary.overdue', 2));
        $this->get(route('tenant.contracts.show', [$tenant, $contract]))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('osSummary.total', 5)->where('osSummary.overdue', 1)
                ->where('osSummary.recent', fn ($items) => collect($items)->every(fn ($item) => $item['contract'] === $contract->code)));
    }

    private function context(): array
    {
        $tenant = Tenant::create(['slug' => 'summary-'.str()->random(8), 'name' => 'Resumo OS', 'plan' => 'starter', 'status' => 'active']);
        $user = User::factory()->create();
        $tenant->memberships()->create(['user_id' => $user->id, 'role' => 'tenant_admin', 'status' => 'active']);
        $contract = $this->contract($tenant);
        $participant = ContractParticipant::create(['tenant_id' => $tenant->id, 'contract_id' => $contract->id, 'user_id' => $user->id,
            'side' => 'client', 'role' => 'manager', 'status' => 'active']);

        return [$tenant, $user, $contract, $participant];
    }

    private function contract(Tenant $tenant): Contract
    {
        return Contract::create(['tenant_id' => $tenant->id, 'code' => 'CT-'.str()->random(8), 'name' => 'Contrato de supervisão', 'status' => 'active']);
    }

    private function order(Contract $contract, User $user, string $status, string $deadline = '2099-01-01'): OsDnitOrder
    {
        $road = OsDnitHighway::firstOrCreate(['tenant_id' => $contract->tenant_id, 'contract_id' => $contract->id, 'name' => 'BR-364/AC'], ['active' => true]);

        return OsDnitOrder::create(['tenant_id' => $contract->tenant_id, 'contract_id' => $contract->id, 'highway_id' => $road->id,
            'created_by_id' => $user->id, 'request_number' => sprintf('%03d/2026', ++$this->sequence), 'supervision_lot' => 'Lote 01',
            'highway_name' => $road->name, 'km_start' => 1, 'km_end' => 2, 'subject' => 'Demanda de topografia',
            'desired_deadline' => $deadline, 'technical_need' => 'Descrição técnica.', 'justification' => 'Justificativa.', 'status' => $status]);
    }
}
