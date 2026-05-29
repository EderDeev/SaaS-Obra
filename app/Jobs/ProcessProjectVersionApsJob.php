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

    public function __construct(private readonly int $versionId)
    {
    }

    public function handle(AutodeskApsService $aps): void
    {
        $version = ProjectDocumentVersion::query()
            ->with(['document'])
            ->find($this->versionId);

        if (! $version || $version->trashed() || $version->document?->trashed()) {
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

        try {
            $aps->submitVersion($version);
        } catch (Throwable $exception) {
            report($exception);

            $version->forceFill([
                'derivative_status' => 'failed',
                'processed_at' => now(),
            ])->save();
        }
    }
}
