<?php

namespace App\Jobs;

use App\Models\ProjectDocumentVersion;
use App\Services\AutodeskApsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class ProcessProjectVersionApsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 600;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [30, 120];

    public function __construct(private readonly int $versionId)
    {
        $this->onQueue((string) config('services.autodesk_aps.queue', 'aps'));
    }

    public function handle(AutodeskApsService $aps): void
    {
        $version = ProjectDocumentVersion::query()
            ->with(['document'])
            ->find($this->versionId);

        if (! $version || $version->trashed() || $version->document?->trashed() || $version->status === 'reprovado') {
            return;
        }

        if ($version->derivative_status === 'ready') {
            return;
        }

        if (! $aps->isConfigured()) {
            $version->forceFill([
                'derivative_status' => 'not_submitted',
                'submitted_to_aps_at' => null,
                'processed_at' => null,
            ])->save();

            return;
        }

        $aps->submitVersion($version);
    }

    public function failed(?Throwable $exception): void
    {
        if ($exception) {
            report($exception);
        }

        $version = ProjectDocumentVersion::query()->find($this->versionId);

        if (! $version || $version->trashed() || $version->status === 'reprovado') {
            return;
        }

        $version->forceFill([
            'derivative_status' => 'failed',
            'processed_at' => now(),
        ])->save();
    }
}
