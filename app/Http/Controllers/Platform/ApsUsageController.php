<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\ProjectDocumentVersion;
use App\Models\Tenant;
use App\Services\AutodeskApsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ApsUsageController extends Controller
{
    public function index(AutodeskApsService $aps): Response
    {
        $limitBytes = (int) config('services.autodesk_aps.storage_limit_bytes', 5 * 1024 * 1024 * 1024);
        $apsConfigured = $aps->isConfigured();
        $apsVersionsQuery = ProjectDocumentVersion::query()
            ->where(function ($query): void {
                $query->whereNotNull('aps_object_id')
                    ->orWhereNotNull('aps_urn');
            });

        $statusCounts = (clone $apsVersionsQuery)
            ->selectRaw('derivative_status, count(*) as total')
            ->groupBy('derivative_status')
            ->pluck('total', 'derivative_status')
            ->map(fn ($count): int => (int) $count);

        $liveBucket = [
            'configured' => $apsConfigured,
            'bucket_key' => $apsConfigured ? $aps->bucketKeyName() : null,
            'region' => $apsConfigured ? $aps->bucketRegion() : null,
            'limit_bytes' => $limitBytes,
            'bucket' => null,
            'objects' => [],
            'object_count' => 0,
            'total_size' => 0,
            'truncated' => false,
            'error' => null,
        ];

        if ($apsConfigured) {
            try {
                $summary = $aps->bucketStorageSummary(500);
                $liveBucket = array_merge($liveBucket, $summary);
            } catch (\Throwable $exception) {
                report($exception);
                $liveBucket['error'] = $exception->getMessage();
            }
        }

        $localUsageByTenant = (clone $apsVersionsQuery)
            ->selectRaw('tenant_id, count(*) as aps_versions_count')
            ->selectRaw('coalesce(sum(file_size), 0) as aps_source_bytes')
            ->selectRaw("sum(case when derivative_status = 'ready' then 1 else 0 end) as ready_count")
            ->selectRaw("sum(case when derivative_status in ('queued', 'processing') then 1 else 0 end) as processing_count")
            ->selectRaw("sum(case when derivative_status = 'failed' then 1 else 0 end) as failed_count")
            ->groupBy('tenant_id')
            ->get()
            ->keyBy(fn (ProjectDocumentVersion $version): int => (int) $version->tenant_id);

        $bucketUsageByTenant = collect($liveBucket['objects'])
            ->reduce(function (array $usage, array $object): array {
                $tenantId = $this->tenantIdFromObject($object);

                if ($tenantId === null) {
                    return $usage;
                }

                $usage[$tenantId] ??= ['bytes' => 0, 'objects_count' => 0];
                $usage[$tenantId]['bytes'] += (int) ($object['size'] ?? 0);
                $usage[$tenantId]['objects_count']++;

                return $usage;
            }, []);

        $hasLiveBucketUsage = $liveBucket['configured'] && $liveBucket['error'] === null;
        $tenantRows = Tenant::query()
            ->withCount(['projectDocuments as project_documents_count'])
            ->get(['id', 'name', 'slug'])
            ->map(function (Tenant $tenant) use ($bucketUsageByTenant, $localUsageByTenant): array {
                $localUsage = $localUsageByTenant->get($tenant->id);
                $bucketUsage = $bucketUsageByTenant[$tenant->id] ?? ['bytes' => 0, 'objects_count' => 0];

                return [
                    'id' => $tenant->id,
                    'name' => $tenant->name,
                    'slug' => $tenant->slug,
                    'project_documents_count' => (int) $tenant->project_documents_count,
                    'aps_versions_count' => (int) ($localUsage?->aps_versions_count ?? 0),
                    'aps_source_bytes' => (int) ($localUsage?->aps_source_bytes ?? 0),
                    'aps_bucket_bytes' => (int) $bucketUsage['bytes'],
                    'aps_bucket_objects_count' => (int) $bucketUsage['objects_count'],
                    'ready_count' => (int) ($localUsage?->ready_count ?? 0),
                    'processing_count' => (int) ($localUsage?->processing_count ?? 0),
                    'failed_count' => (int) ($localUsage?->failed_count ?? 0),
                ];
            })
            ->filter(fn (array $tenant): bool => $tenant['aps_versions_count'] > 0 || $tenant['aps_bucket_objects_count'] > 0)
            ->sortByDesc(fn (array $tenant): int => $hasLiveBucketUsage
                ? $tenant['aps_bucket_bytes']
                : $tenant['aps_source_bytes'])
            ->values();

        $attributedBucketBytes = (int) $tenantRows->sum('aps_bucket_bytes');
        $attributedBucketObjects = (int) $tenantRows->sum('aps_bucket_objects_count');

        $recentVersions = (clone $apsVersionsQuery)
            ->with([
                'tenant:id,name,slug',
                'document' => fn ($query) => $query
                    ->withTrashed()
                    ->with([
                        'contract:id,code,name',
                        'obra:id,nome,codigo',
                        'disciplina:id,nome,sigla',
                    ]),
                'uploader:id,name,email',
            ])
            ->latest('submitted_to_aps_at')
            ->latest()
            ->limit(80)
            ->get();

        return Inertia::render('Platform/Aps/Index', [
            'stats' => [
                'storage_limit_bytes' => $limitBytes,
                'local_project_bytes' => (int) ProjectDocumentVersion::sum('file_size'),
                'aps_source_bytes' => (int) (clone $apsVersionsQuery)->sum('file_size'),
                'project_versions_count' => ProjectDocumentVersion::count(),
                'aps_versions_count' => (clone $apsVersionsQuery)->count(),
                'ready_count' => (int) ($statusCounts['ready'] ?? 0),
                'processing_count' => (int) (($statusCounts['queued'] ?? 0) + ($statusCounts['processing'] ?? 0)),
                'failed_count' => (int) ($statusCounts['failed'] ?? 0),
                'tenants_with_aps_usage' => $tenantRows->count(),
                'unattributed_bucket_bytes' => max(0, (int) $liveBucket['total_size'] - $attributedBucketBytes),
                'unattributed_bucket_objects_count' => max(0, (int) $liveBucket['object_count'] - $attributedBucketObjects),
            ],
            'liveBucket' => $liveBucket,
            'tenantRows' => $tenantRows,
            'recentVersions' => $recentVersions,
        ]);
    }

    /**
     * @param  array<string, mixed>  $object
     */
    private function tenantIdFromObject(array $object): ?int
    {
        $objectKey = rawurldecode((string) ($object['object_key'] ?? ''));

        if (preg_match('/^tenant-(\d+)-project-version-\d+-/i', $objectKey, $matches) !== 1) {
            return null;
        }

        return (int) $matches[1];
    }

    public function destroyVersion(Request $request, AutodeskApsService $aps, ProjectDocumentVersion $version): RedirectResponse
    {
        if (! $aps->isConfigured()) {
            return back()->with('error', 'APS ainda nao esta configurado no .env.');
        }

        if (! $version->aps_object_id && ! $version->aps_urn) {
            return back()->with('success', 'Esta versao ja nao possui arquivo no APS.');
        }

        try {
            $aps->deleteVersionFromAps($version);
        } catch (\Throwable $exception) {
            report($exception);

            return back()->with('error', 'Nao foi possivel limpar o arquivo no APS: '.$exception->getMessage());
        }

        return back()->with('success', 'Arquivo removido do APS. O registro local e o arquivo original foram mantidos.');
    }
}
