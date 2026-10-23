<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\OsDnitOrder;
use App\Models\OsDnitProduct;
use App\Models\Tenant;
use App\Models\User;
use App\Services\OsDnitSandbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OsDnitSandboxTest extends TestCase
{
    use RefreshDatabase;

    public function test_mass_scenario_uses_real_workflow_and_preserves_other_data_and_reruns(): void
    {
        $viewer = User::factory()->create(['is_platform_admin' => true]);
        $existing = Tenant::create(['slug' => 'existing', 'name' => 'Preservar', 'status' => 'active', 'plan' => 'starter']);
        $oldPassword = $viewer->password;
        $result = app(OsDnitSandbox::class)->create('teste-os-dnit', $viewer, 'OnlyInMemory!73');
        $report = $result['report'];
        $this->assertSame(120, $report['orders']);
        $this->assertSame(80, $report['products']);
        $this->assertSame(48, $report['activities']);
        $this->assertSame(17, $report['users_created']);
        $this->assertSame(0, $report['external_emails']);
        $this->assertGreaterThan(0, $report['mail_messages_in_memory']);
        $this->assertCount(41, $report['checks']);
        $this->assertSame('Preservar', $existing->fresh()->name);
        $this->assertSame($oldPassword, $viewer->fresh()->password);
        $this->assertSame(32, Activity::where('tenant_id', $result['tenant']->id)->whereNotNull('os_dnit_order_id')->count());
        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(48, OsDnitOrder::where('status', 'approved')->count());
        $again = app(OsDnitSandbox::class)->create('teste-os-dnit', $viewer, 'DoNotReset!73');
        $this->assertFalse($again['created']);
        $this->assertCount(0, $again['accounts']);
        $this->assertSame(120, OsDnitOrder::count());
        $this->assertSame(80, OsDnitProduct::count());
        $this->assertSame(48, Activity::count());
    }
}
