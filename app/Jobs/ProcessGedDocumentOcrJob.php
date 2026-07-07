<?php

namespace App\Jobs;

use App\Models\GedDocument;
use App\Models\GedDocumentEvent;
use App\Services\GedOcrService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class ProcessGedDocumentOcrJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 1200;

    public function __construct(private readonly int $documentId)
    {
        $this->onQueue((string) config('ged.ocr.queue', 'ged'));
    }

    public function handle(GedOcrService $ocr): void
    {
        $document = GedDocument::query()->find($this->documentId);

        if (! $document || $document->trashed()) {
            return;
        }

        if ($document->status === 'indexed' && filled($document->extracted_text)) {
            return;
        }

        $document->forceFill([
            'status' => 'processing',
            'metadata' => $this->mergeOcrMetadata($document, [
                'status' => 'processing',
                'queued_at' => data_get($document->metadata, 'ocr.queued_at') ?: now()->toDateTimeString(),
                'started_at' => now()->toDateTimeString(),
                'finished_at' => null,
                'engine' => 'ocrmypdf',
                'message' => 'Documento em processamento OCR.',
            ]),
        ])->save();

        $this->logEvent($document, 'ocr.started', 'OCR iniciado', 'O processamento OCR do documento foi iniciado.', [
            'engine' => 'ocrmypdf',
        ]);

        $result = $ocr->process($document);
        $text = $this->cleanUtf8((string) ($result['text'] ?? ''));

        $document->forceFill([
            'status' => 'indexed',
            'extracted_text' => $text !== '' ? $text : null,
            'archive_path' => $result['archive_path'] ?: $document->archive_path,
            'page_count' => $result['page_count'] ?: $document->page_count,
            'processed_at' => now(),
            'metadata' => $this->mergeOcrMetadata($document, [
                'status' => 'done',
                'finished_at' => now()->toDateTimeString(),
                'engine' => $result['engine'] ?? 'ocrmypdf',
                'message' => $result['message'] ?? 'OCR processado.',
                'text_length' => mb_strlen($text),
            ]),
        ])->save();

        $this->logEvent($document, 'ocr.completed', 'OCR concluído', $result['message'] ?? 'OCR processado.', [
            'engine' => $result['engine'] ?? 'ocrmypdf',
            'text_length' => mb_strlen($text),
            'page_count' => $result['page_count'] ?: $document->page_count,
            'archive_path' => $result['archive_path'] ?: $document->archive_path,
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        $document = GedDocument::query()->find($this->documentId);

        if (! $document) {
            return;
        }

        $friendlyMessage = $this->friendlyError($exception);
        $missingEngine = str_contains($friendlyMessage, 'Binário OCR não encontrado')
            || (str_contains($friendlyMessage, 'Bin') && str_contains($friendlyMessage, 'OCR') && str_contains($friendlyMessage, 'PATH'));

        $document->forceFill([
            'status' => 'failed',
            'processed_at' => now(),
            'metadata' => $this->mergeOcrMetadata($document, [
                'status' => $missingEngine ? 'missing_engine' : 'failed',
                'finished_at' => now()->toDateTimeString(),
                'engine' => 'ocrmypdf',
                'message' => $friendlyMessage,
            ]),
        ])->save();

        $this->logEvent($document, $missingEngine ? 'ocr.missing_engine' : 'ocr.failed', $missingEngine ? 'Motor OCR não encontrado' : 'Falha no OCR', $friendlyMessage, [
            'engine' => 'ocrmypdf',
        ]);
    }

    private function mergeOcrMetadata(GedDocument $document, array $ocr): array
    {
        $metadata = $document->metadata ?: [];
        $metadata['ocr'] = array_merge($metadata['ocr'] ?? [], $this->cleanArray($ocr));

        return $metadata;
    }

    private function cleanArray(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = $this->cleanArray($value);
            } elseif (is_string($value)) {
                $data[$key] = $this->cleanUtf8($value);
            }
        }

        return $data;
    }

    private function cleanUtf8(string $value): string
    {
        if (mb_check_encoding($value, 'UTF-8')) {
            return $value;
        }

        return mb_convert_encoding($value, 'UTF-8', 'UTF-8, Windows-1252, ISO-8859-1');
    }

    private function friendlyError(?Throwable $exception): string
    {
        $message = $exception?->getMessage() ?: 'Falha desconhecida no OCR.';

        if (str_contains($message, 'Binário OCR não encontrado')) {
            return $message.'. Instale OCRmyPDF/Tesseract/Poppler no servidor ou ajuste as variáveis GED_OCR_*_BIN.';
        }

        if (str_contains($message, 'ocrmypdf')) {
            return 'Falha ao executar OCRmyPDF. Verifique se OCRmyPDF, Tesseract e os idiomas OCR estão instalados no servidor.';
        }

        return $message;
    }

    private function logEvent(GedDocument $document, string $type, string $title, ?string $description = null, array $properties = []): void
    {
        GedDocumentEvent::create([
            'tenant_id' => $document->tenant_id,
            'document_id' => $document->id,
            'actor_id' => null,
            'event_type' => $type,
            'title' => $title,
            'description' => $description,
            'properties' => $properties === [] ? null : $properties,
        ]);
    }
}
