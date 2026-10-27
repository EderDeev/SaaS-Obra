<?php

namespace Tests\Feature;

use App\Models\{Contract, Tenant, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantContractValueTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['slug' => 'contract-values', 'name' => 'Contracts', 'status' => 'active', 'plan' => 'starter']);
        $user = User::factory()->create();
        $this->tenant->memberships()->create(['user_id' => $user->id, 'role' => 'tenant_owner', 'status' => 'active']);
        $this->actingAs($user);
    }

    public function test_contract_can_be_created_without_a_value_then_filled_and_edited(): void
    {
        foreach ([[], ['total_value' => '']] as $index => $value) {
            $this->post(route('tenant.contracts.store', $this->tenant), [...$value, 'code' => 'VALUE-'.$index,
                'currency' => 'BRL', 'city' => 'Belém', 'state' => 'PA', 'starts_at' => '2026-10-01', 'ends_at' => '2027-10-01'])
                ->assertSessionHasNoErrors()->assertRedirect();
            $contract = $this->tenant->contracts()->where('code', 'VALUE-'.$index)->firstOrFail();
            $this->assertNull($contract->total_value);
            foreach (['1250.50', '2000.00', '0.00'] as $amount) {
                $this->patch(route('tenant.contracts.parametrizacao.update', [$this->tenant, $contract]), ['total_value' => $amount])
                    ->assertSessionHasNoErrors()->assertRedirect();
                $this->assertSame($amount, $contract->fresh()->total_value);
            }
            $this->patch(route('tenant.contracts.parametrizacao.update', [$this->tenant, $contract]), ['total_value' => ''])
                ->assertSessionHasNoErrors();
            $this->assertNull($contract->fresh()->total_value);
        }
    }

    public function test_invalid_values_are_rejected_and_unrelated_updates_preserve_the_amount(): void
    {
        $contract = $this->tenant->contracts()->create(['code' => 'VALUE', 'name' => 'Value', 'status' => 'planning', 'total_value' => 150]);
        $url = route('tenant.contracts.parametrizacao.update', [$this->tenant, $contract]);
        foreach ([-1, 'invalid'] as $value) $this->patch($url, ['total_value' => $value])->assertSessionHasErrors('total_value');
        $this->patch($url, ['obra_id' => null])->assertSessionHasNoErrors();
        $this->assertSame('150.00', $contract->fresh()->total_value);
    }

    public function test_another_tenants_contract_cannot_be_updated(): void
    {
        $other = Tenant::create(['slug' => 'other-value', 'name' => 'Other', 'status' => 'active', 'plan' => 'starter']);
        $contract = $other->contracts()->create(['code' => 'OTHER', 'name' => 'Other', 'status' => 'active', 'total_value' => 90]);
        $this->patch(route('tenant.contracts.parametrizacao.update', [$this->tenant, $contract]), ['total_value' => 99])->assertNotFound();
        $this->assertSame('90.00', $contract->fresh()->total_value);
    }
}
