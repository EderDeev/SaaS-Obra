<?php

namespace Tests\Feature;

use App\Models\ContractParticipant;
use App\Models\Tenant;
use App\Models\User;
use App\Support\ContractPermissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ContractFinanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_baseline_monthly_billing_and_later_receipt_are_reported_by_contract(): void
    {
        $tenant = Tenant::create(['slug' => 'financeiro', 'name' => 'Diretoria', 'status' => 'active', 'plan' => 'starter']);
        $contract = $tenant->contracts()->create(['code' => 'CT-1', 'name' => 'Contrato', 'status' => 'active']);
        $anotherContract = $tenant->contracts()->create(['code' => 'CT-2', 'name' => 'Outro contrato', 'status' => 'active']);
        $this->actingAs(User::factory()->create(['is_platform_admin' => true]));
        $baselineUrl = route('tenant.contract-finance.baseline.update', [$tenant, $contract]);
        $monthlyUrl = route('tenant.contract-finance.months.update', [$tenant, $contract]);

        $this->put($baselineUrl, ['expected_amount' => '1000000.25', 'expected_date' => '2027-12-31'])
            ->assertRedirect(route('tenant.contract-finance.settings', [$tenant, 'contract_id' => $contract->id]));

        $billing = ['reference_month' => '2026-11', 'billed_amount' => '150000.55', 'billed_date' => '2026-11-28',
            'revenue_amount' => '150000.55', 'operating_cost' => '100000.00'];
        $this->put($monthlyUrl, [...$billing, 'revision' => 0, 'receipts' => []])->assertRedirect();
        $this->assertDatabaseCount('contract_finance_receipts', 0);
        $this->put($monthlyUrl, [...$billing, 'revision' => 1, 'receipts' => [['received_amount' => '120000.10']]])
            ->assertSessionHasErrors('receipts.0.received_date');
        $this->put($monthlyUrl, [...$billing, 'revision' => 1, 'receipts' => [
            ['received_amount' => '80000.10', 'received_date' => '2026-12-05'],
            ['received_amount' => '40000.00', 'received_date' => '2027-01-05'],
        ]])
            ->assertSessionHasNoErrors();
        $this->assertDatabaseCount('contract_finance_months', 1);
        $this->put($monthlyUrl, [...$billing, 'revision' => 1, 'receipts' => []])->assertSessionHasErrors('revision');
        $this->assertDatabaseCount('contract_finance_receipts', 2);

        $this->get(route('tenant.contract-finance.index', $tenant))
            ->assertOk()->assertInertia(fn (Assert $page) => $page->where('selectedContract', null)->has('months', 0)
                ->where('contracts.0.billed_total', fn ($value) => abs((float) $value - 150000.55) < 0.001)
                ->where('contracts.0.received_total', fn ($value) => abs((float) $value - 120000.10) < 0.001));

        $this->put(route('tenant.contract-finance.targets.update', [$tenant, $contract]), [
            'year' => 2027, 'revenue_target' => '300000.00', 'margin_target' => '18.00',
        ])->assertSessionHasNoErrors();

        $this->get(route('tenant.contract-finance.index', [$tenant, 'contract_id' => $contract->id]))
            ->assertOk()->assertInertia(fn (Assert $page) => $page->component('Tenant/ContractFinance/Index')
                ->where('months.0.reference_month', '2026-11-01')
                ->where('months.0.billed_date', '2026-11-28')
                ->where('months.0.receipts.0.received_date', '2026-12-05')
                ->where('months.0.receipts.1.received_date', '2027-01-05'));
        $this->get(route('tenant.contract-finance.dashboard', [$tenant, 'contract_id' => $contract->id]))
            ->assertOk()->assertInertia(fn (Assert $page) => $page
                ->where('baseline.expected_date', '2027-12-31')
                ->where('report.0.contracts.0.outstanding', fn ($value) => abs((float) $value - 30000.45) < 0.001)
                ->where('report.0.contracts.0.result', fn ($value) => abs((float) $value - 50000.55) < 0.001));
        $this->get(route('tenant.contract-finance.dashboard', [$tenant, 'contract_id' => $contract->id, 'year' => 2027]))
            ->assertOk()->assertInertia(fn (Assert $page) => $page
                ->where('report.0.contracts.0.series.0.month', '2027-01')
                ->where('report.0.contracts.0.revenue', null)
                ->where('report.0.contracts.0.received', 40000)
                ->where('report.0.contracts.0.target.margin_target', 18));
        $this->get(route('tenant.contract-finance.index', [$tenant, 'contract_id' => $anotherContract->id]))
            ->assertOk()->assertInertia(fn (Assert $page) => $page->has('months', 0)->where('baseline', null));
    }

    public function test_read_only_members_cannot_save_and_other_directorates_cannot_be_accessed(): void
    {
        $tenant = Tenant::create(['slug' => 'financeiro-leitura', 'name' => 'Diretoria', 'status' => 'active', 'plan' => 'starter']);
        $other = Tenant::create(['slug' => 'financeiro-externo', 'name' => 'Outra diretoria', 'status' => 'active', 'plan' => 'starter']);
        $contract = $tenant->contracts()->create(['code' => 'CT-1', 'name' => 'Contrato', 'status' => 'active']);
        $external = $other->contracts()->create(['code' => 'CT-2', 'name' => 'Outro contrato', 'status' => 'active']);
        $viewer = User::factory()->create();
        $tenant->memberships()->create(['user_id' => $viewer->id, 'role' => 'member', 'status' => 'active']);
        ContractParticipant::create([
            'tenant_id' => $tenant->id, 'contract_id' => $contract->id, 'user_id' => $viewer->id,
            'side' => 'manager', 'role' => 'team_member', 'status' => 'active', 'contract_permissions' => [ContractPermissions::VIEW],
        ]);

        $this->actingAs($viewer)->get(route('tenant.contract-finance.settings', $tenant))
            ->assertOk()->assertInertia(fn (Assert $page) => $page->where('canManage', false)->has('manageableContractIds', 0));
        $this->put(route('tenant.contract-finance.baseline.update', [$tenant, $contract]))->assertForbidden();
        $this->put(route('tenant.contract-finance.months.update', [$tenant, $contract]))->assertForbidden();
        $this->get(route('tenant.contract-finance.index', [$tenant, 'contract_id' => $external->id]))->assertNotFound();
        $this->actingAs(User::factory()->create(['is_platform_admin' => true]))
            ->put(route('tenant.contract-finance.baseline.update', [$tenant, $external]))->assertNotFound();
    }
}
