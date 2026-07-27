<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\ProjectDocument;
use App\Models\ProjectDocumentVersion;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AutodeskApsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery;
use Tests\TestCase;

class PlatformApsUsageTest extends TestCase
{
    use RefreshDatabase;

    public function test_platform_admin_sees_real_aps_consumption_grouped_by_tenant(): void
    {
        config(['services.autodesk_aps.storage_limit_bytes' => 10_000]);

        $admin = User::factory()->create(['is_platform_admin' => true]);
        $alpha = $this->tenantWithApsVersion('alpha', 'Alpha Engenharia', 1_200, 'ready');
        $beta = $this->tenantWithApsVersion('beta', 'Beta Engenharia', 800, 'processing');

        $aps = Mockery::mock(AutodeskApsService::class);
        $aps->shouldReceive('isConfigured')->once()->andReturnTrue();
        $aps->shouldReceive('bucketKeyName')->once()->andReturn('bucket-test');
        $aps->shouldReceive('bucketRegion')->once()->andReturn('US');
        $aps->shouldReceive('bucketStorageSummary')->once()->with(500)->andReturn([
            'bucket' => ['bucketKey' => 'bucket-test'],
            'objects' => [
                ['object_key' => "tenant-{$alpha->id}-project-version-1-alpha.rvt", 'size' => 700],
                ['object_key' => "tenant-{$alpha->id}-project-version-2-alpha.ifc", 'size' => 300],
                ['object_key' => "tenant-{$beta->id}-project-version-3-beta.rvt", 'size' => 500],
                ['object_key' => 'legacy-project-file.rvt', 'size' => 250],
            ],
            'object_count' => 4,
            'total_size' => 1_750,
            'truncated' => false,
        ]);
        $this->app->instance(AutodeskApsService::class, $aps);

        $this->actingAs($admin)
            ->get(route('platform.aps.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Platform/Aps/Index')
                ->where('stats.tenants_with_aps_usage', 2)
                ->where('stats.unattributed_bucket_bytes', 250)
                ->where('stats.unattributed_bucket_objects_count', 1)
                ->where('tenantRows.0.id', $alpha->id)
                ->where('tenantRows.0.aps_bucket_bytes', 1_000)
                ->where('tenantRows.0.aps_bucket_objects_count', 2)
                ->where('tenantRows.0.aps_source_bytes', 1_200)
                ->where('tenantRows.0.ready_count', 1)
                ->where('tenantRows.1.id', $beta->id)
                ->where('tenantRows.1.aps_bucket_bytes', 500)
                ->where('tenantRows.1.processing_count', 1)
            );
    }

    private function tenantWithApsVersion(string $slug, string $name, int $fileSize, string $status): Tenant
    {
        $tenant = Tenant::create([
            'slug' => $slug,
            'name' => $name,
            'plan' => 'starter',
            'status' => 'active',
        ]);
        $contract = Contract::create([
            'tenant_id' => $tenant->id,
            'code' => strtoupper($slug).'-001',
            'name' => 'Contrato '.$name,
            'status' => 'active',
        ]);
        $document = ProjectDocument::create([
            'tenant_id' => $tenant->id,
            'contract_id' => $contract->id,
            'title' => 'Projeto '.$name,
            'code' => strtoupper($slug).'-PRJ-001',
            'document_type' => 'projeto',
            'status' => 'ativo',
        ]);

        ProjectDocumentVersion::create([
            'tenant_id' => $tenant->id,
            'project_document_id' => $document->id,
            'revision' => 'R00',
            'original_name' => $slug.'.rvt',
            'file_path' => 'projects/'.$slug.'.rvt',
            'file_size' => $fileSize,
            'aps_object_id' => 'urn:adsk.objects:os.object:bucket/'.$slug.'.rvt',
            'aps_urn' => base64_encode($slug),
            'derivative_status' => $status,
            'submitted_to_aps_at' => now(),
        ]);

        return $tenant;
    }
}
