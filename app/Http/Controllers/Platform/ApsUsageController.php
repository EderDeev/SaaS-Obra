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

        $tenantRows = Tenant::query()
            ->withCount(['projectDocuments as project_documents_count'])
            ->orderBy('name')
            ->get(['id', 'name', 'slug'])
            ->map(function (Tenant $tenant) use ($apsVersionsQuery): array {
                $versions = (clone $apsVersionsQuery)
                    ->where('tenant_id', $tenant->id);

                return [
                    'id' => $tenant->id,
                    'name' => $tenant->name,
                    'slug' => $tenant->slug,
                    'aps_versions_count' => (clone $versions)->count(),
                    'aps_source_bytes' => (int) (clone $versions)->sum('file_size'),
                    'ready_count' => (clone $versions)->where('derivative_status', 'ready')->count(),
                    'failed_count' => (clone $versions)->where('derivative_status', 'failed')->count(),
                ];
            })
            ->filter(fn (array $tenant): bool => $tenant['aps_versions_count'] > 0)
            ->values();

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

        $liveBucket = [
            'configured' => $aps->isConfigured(),
            'bucket_key' => $aps->isConfigured() ? $aps->bucketKeyName() : null,
            'region' => $aps->isConfigured() ? $aps->bucketRegion() : null,
            'limit_bytes' => $limitBytes,
            'bucket' => null,
            'objects' => [],
            'object_count' => 0,
            'total_size' => 0,
            'truncated' => false,
            'error' => null,
        ];

        if ($aps->isConfigured()) {
            try {
                $summary = $aps->bucketStorageSummary(500);
                $liveBucket = array_merge($liveBucket, $summary);
            } catch (\Throwable $exception) {
                report($exception);
                $liveBucket['error'] = $exception->getMessage();
            }
        }

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
            ],
            'liveBucket' => $liveBucket,
            'tenantRows' => $tenantRows,
            'recentVersions' => $recentVersions,
        ]);
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
