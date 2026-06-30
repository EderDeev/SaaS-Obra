<?php

namespace App\Jobs;

use App\Http\Controllers\Tenant\OrcamentoController;
use App\Models\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ImportOrcamentoComposicoesJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 3600;

    public int $tries = 1;

    public function __construct(
        private readonly int $tenantId,
        private readonly int $userId,
        private readonly string $type,
        private readonly string $path,
        private readonly array $data,
    ) {
        $this->onQueue('imports');
    }

    public function handle(OrcamentoController $controller): void
    {
        $tenant = Tenant::query()->findOrFail($this->tenantId);

        try {
            $result = $controller->runQueuedComposicoesImport(
                $this->type,
                Storage::disk('local')->path($this->path),
                $tenant,
                $this->userId,
                $this->data,
            );

            Log::info('Importacao de composicoes concluida em segundo plano.', [
                'tenant_id' => $this->tenantId,
                'user_id' => $this->userId,
                'type' => $this->type,
                'result' => $result,
            ]);
        } finally {
            Storage::disk('local')->delete($this->path);
        }
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('Importacao de composicoes falhou em segundo plano.', [
            'tenant_id' => $this->tenantId,
            'user_id' => $this->userId,
            'type' => $this->type,
            'path' => $this->path,
            'error' => $exception?->getMessage(),
        ]);
    }
}
