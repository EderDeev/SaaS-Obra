<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\ProjectDocument;
use App\Models\ProjectDocumentVersion;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PlatformDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_platform_dashboard_presents_executive_metrics(): void
    {
        $admin = User::factory()->create(['is_platform_admin' => true]);
        User::factory()->create();

        $active = $this->tenant('alpha', 'Alpha Engenharia', 'growth', 'active');
        $this->tenant('beta', 'Beta Engenharia', 'starter', 'trial');
        $this->tenant('gamma', 'Gamma Engenharia', 'enterprise', 'suspended');

        $contract = Contract::create([
            'tenant_id' => $active->id,
            'code' => 'ALPHA-001',
            'name' => 'Contrato Alpha',
            'status' => 'active',
        ]);
        $document = ProjectDocument::create([
            'tenant_id' => $active->id,
            'contract_id' => $contract->id,
            'title' => 'Projeto Alpha',
            'code' => 'ALPHA-PRJ-001',
            'document_type' => 'projeto',
            'status' => 'ativo',
        ]);

        ProjectDocumentVersion::create([
            'tenant_id' => $active->id,
            'project_document_id' => $document->id,
            'revision' => 'R00',
            'original_name' => 'alpha.rvt',
            'file_path' => 'projects/alpha.rvt',
            'file_size' => 2_048,
            'aps_object_id' => 'urn:adsk.objects:os.object:bucket/alpha.rvt',
            'aps_urn' => base64_encode('alpha'),
            'derivative_status' => 'ready',
            'submitted_to_aps_at' => now(),
        ]);

        $this->actingAs($admin)
            ->get(route('platform.dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Platform/Dashboard')
                ->where('stats.tenants', 3)
                ->where('stats.active_tenants', 1)
                ->where('stats.contracts', 1)
                ->where('stats.users', 2)
                ->where('stats.storage_bytes', 2_048)
                ->where('tenantStatuses.0', ['key' => 'active', 'total' => 1])
                ->where('tenantStatuses.1', ['key' => 'trial', 'total' => 1])
                ->where('tenantStatuses.2', ['key' => 'suspended', 'total' => 1])
                ->where('tenantPlans.0', ['key' => 'starter', 'total' => 1])
                ->where('tenantPlans.1', ['key' => 'growth', 'total' => 1])
                ->where('tenantPlans.2', ['key' => 'enterprise', 'total' => 1])
                ->where('storageUsage.0.id', $active->id)
                ->where('storageUsage.0.modules.documentation', 0)
                ->where('storageUsage.0.modules.projects', 2_048)
                ->where('storageUsage.0.modules.rnc', 0)
                ->where('storageUsage.0.total_bytes', 2_048)
                ->has('storageUsage', 3)
                ->has('recentTenants', 3)
            );
    }

    private function tenant(string $slug, string $name, string $plan, string $status): Tenant
    {
        return Tenant::create(compact('slug', 'name', 'plan', 'status'));
    }
}
