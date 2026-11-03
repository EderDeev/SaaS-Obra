<?php

namespace Tests\Feature;

use App\Models\{MobileApiToken, Tenant, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class MobileTenantSelectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_mobile_session_selects_only_active_authorized_tenants_and_keeps_tokens_scoped(): void
    {
        $user = User::factory()->create(['password' => Hash::make('mobile-test-password'), 'must_change_password' => false]);
        $tenants = collect(['Ambiente A', 'Ambiente B', 'Outro ambiente', 'Inativo'])->map(fn ($name, $i) => Tenant::create(['name' => $name, 'slug' => 'mobile-'.$i, 'status' => $i === 3 ? 'inactive' : 'active', 'plan' => 'starter']));
        foreach ([0, 1, 3] as $index) $tenants[$index]->memberships()->create(['user_id' => $user->id, 'role' => 'tenant_owner', 'status' => 'active']);
        $session = $this->postJson(route('api.mobile.login'), ['email' => $user->email, 'password' => 'mobile-test-password'])->assertOk()->assertJsonCount(2, 'tenants')->json();
        $headers = ['Authorization' => 'Bearer '.$session['token']];
        $next = $this->postJson(route('api.mobile.tenant.switch'), ['tenant_id' => $tenants[1]->id], $headers)->assertOk()->assertJsonPath('tenant.id', $tenants[1]->id)->json();
        $this->getJson(route('api.mobile.bootstrap'), $headers)->assertOk()->assertJsonPath('tenant.id', $tenants[0]->id);
        $this->getJson(route('api.mobile.bootstrap'), ['Authorization' => 'Bearer '.$next['token']])->assertOk()->assertJsonPath('tenant.id', $tenants[1]->id);
        foreach ([2, 3] as $index) $this->postJson(route('api.mobile.tenant.switch'), ['tenant_id' => $tenants[$index]->id], $headers)->assertForbidden();
        $this->assertSame(2, MobileApiToken::count());
        $tenants[0]->memberships()->update(['status' => 'inactive']);
        $this->getJson(route('api.mobile.bootstrap'), $headers)->assertForbidden();
    }
}
