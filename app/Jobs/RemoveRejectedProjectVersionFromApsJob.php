<?php

namespace App\Jobs;

use App\Models\ProjectDocumentVersion;
use App\Services\AutodeskApsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RemoveRejectedProjectVersionFromApsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 5;

    /** @var array<int, int> */
    public array $backoff = [30, 120, 300, 900];

    public function __construct(private readonly int $versionId)
    {
    }

    public function handle(AutodeskApsService $aps): void
    {
        $version = ProjectDocumentVersion::query()->find($this->versionId);

        if (! $version || $version->status !== 'reprovado') {
            return;
        }

        if ($version->aps_object_id || $version->aps_urn) {
            $aps->deleteVersionFromAps($version);
            $version->refresh();
        }

        $version->forceFill([
            'aps_object_id' => null,
            'aps_urn' => null,
            'derivative_status' => 'removed',
            'submitted_to_aps_at' => null,
            'processed_at' => now(),
        ])->save();
    }
}
