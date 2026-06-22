<?php

use App\Models\ProjectDocumentVersion;
use App\Services\AutodeskApsService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$envKeys = [
    'AUTODESK_APS_CLIENT_ID',
    'AUTODESK_APS_CLIENT_SECRET',
    'AUTODESK_APS_BUCKET_KEY',
    'AUTODESK_APS_REGION',
    'AUTODESK_APS_AUTO_PROCESS',
];

echo "APS env\n";
foreach ($envKeys as $key) {
    $value = env($key);
    echo $key.': '.($value ? 'set('.strlen((string) $value).')' : 'missing').PHP_EOL;
}

echo PHP_EOL."Queue\n";
echo 'jobs: '.DB::table('jobs')->count().PHP_EOL;
echo 'failed_jobs: '.DB::table('failed_jobs')->count().PHP_EOL;

$jobs = DB::table('jobs')
    ->orderBy('id')
    ->limit(5)
    ->get(['id', 'queue', 'attempts', 'available_at', 'created_at']);

if ($jobs->isNotEmpty()) {
    echo "pending_jobs_sample:\n";
    foreach ($jobs as $job) {
        echo json_encode($job, JSON_UNESCAPED_UNICODE).PHP_EOL;
    }
}

echo PHP_EOL."Latest project versions\n";
$versions = ProjectDocumentVersion::query()
    ->with('document:id,title,code')
    ->latest('id')
    ->limit(12)
    ->get([
        'id',
        'project_document_id',
        'original_name',
        'file_path',
        'file_size',
        'aps_object_id',
        'aps_urn',
        'derivative_status',
        'created_at',
        'updated_at',
    ]);

foreach ($versions as $version) {
    echo json_encode([
        'id' => $version->id,
        'document' => $version->document?->title,
        'file' => $version->original_name,
        'size' => $version->file_size,
        'status' => $version->derivative_status,
        'has_object' => (bool) $version->aps_object_id,
        'has_urn' => (bool) $version->aps_urn,
        'created_at' => $version->created_at?->format('Y-m-d H:i:s'),
        'updated_at' => $version->updated_at?->format('Y-m-d H:i:s'),
    ], JSON_UNESCAPED_UNICODE).PHP_EOL;
}

if (in_array('--manifest', $argv, true)) {
    echo PHP_EOL."Latest APS manifest\n";

    /** @var AutodeskApsService $aps */
    $aps = $app->make(AutodeskApsService::class);
    $version = $versions->firstWhere('aps_urn');

    if (! $version) {
        echo "No APS URN found.\n";
        exit(0);
    }

    try {
        $manifest = $aps->manifest($version);

        if (! $manifest) {
            echo "Manifest not found yet for version {$version->id}.\n";
            exit(0);
        }

        echo json_encode([
            'version_id' => $version->id,
            'status' => $manifest['status'] ?? null,
            'progress' => $manifest['progress'] ?? null,
            'region' => $manifest['region'] ?? null,
            'type' => $manifest['type'] ?? null,
            'has_derivatives' => ! empty($manifest['derivatives']),
            'messages' => $manifest['messages'] ?? null,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE).PHP_EOL;
    } catch (Throwable $exception) {
        echo 'Manifest check failed: '.$exception->getMessage().PHP_EOL;
        exit(1);
    }
}
