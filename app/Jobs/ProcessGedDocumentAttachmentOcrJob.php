<?php

namespace App\Jobs;

use App\Models\GedDocumentAttachment;
use App\Models\GedDocumentEvent;
use App\Services\GedOcrService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class ProcessGedDocumentAttachmentOcrJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public function timeout(): int
    {
        return max(120, (int) config('ged.ocr.timeout', 300) + 60);
    }

    public function __construct(private readonly int $attachmentId)
    {
        $this->onQueue((string) config('ged.ocr.queue', 'ged'));
    }

    public function handle(GedOcrService $ocr): void
    {
        $attachment = GedDocumentAttachment::query()
            ->with('document')
            ->find($this->attachmentId);

        if (! $attachment || ! $attachment->document || $attachment->document->trashed() || ! $attachment->isPdf()) {
            return;
        }

        if ($attachment->ocr_status === 'indexed' && filled($attachment->extracted_text)) {
            return;
        }

        $attachment->forceFill([
            'ocr_status' => 'processing',
            'ocr_metadata' => $this->mergeOcrMetadata($attachment, [
                'status' => 'processing',
                'queued_at' => data_get($attachment->ocr_metadata, 'queued_at') ?: now()->toDateTimeString(),
                'started_at' => now()->toDateTimeString(),
                'finished_at' => null,
                'engine' => 'ocrmypdf',
                'message' => 'Anexo em processamento OCR.',
                'timeout_seconds' => (int) config('ged.ocr.timeout', 300),
            ]),
        ])->save();

        $this->logEvent($attachment, 'attachment.ocr.started', 'OCR do anexo iniciado', 'O processamento OCR do anexo PDF foi iniciado.');

        $result = $ocr->processAttachment($attachment);
        $text = $this->cleanUtf8((string) ($result['text'] ?? ''));

        $attachment->forceFill([
            'ocr_status' => 'indexed',
            'extracted_text' => $text !== '' ? $text : null,
            'archive_path' => $result['archive_path'] ?: $attachment->archive_path,
            'page_count' => $result['page_count'] ?: $attachment->page_count,
            'processed_at' => now(),
            'ocr_metadata' => $this->mergeOcrMetadata($attachment, [
                'status' => 'done',
                'finished_at' => now()->toDateTimeString(),
                'engine' => $result['engine'] ?? 'ocrmypdf',
                'message' => $result['message'] ?? 'OCR do anexo processado.',
                'text_length' => mb_strlen($text),
            ]),
        ])->save();

        $this->logEvent($attachment, 'attachment.ocr.completed', 'OCR do anexo concluido', $result['message'] ?? 'OCR do anexo processado.', [
            'engine' => $result['engine'] ?? 'ocrmypdf',
            'text_length' => mb_strlen($text),
            'page_count' => $result['page_count'] ?: $attachment->page_count,
            'archive_path' => $result['archive_path'] ?: $attachment->archive_path,
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        $attachment = GedDocumentAttachment::query()
            ->with('document')
            ->find($this->attachmentId);

        if (! $attachment || ! $attachment->document) {
            return;
        }

        $friendlyMessage = $this->friendlyError($exception);
        $missingEngine = str_contains($friendlyMessage, 'Binario OCR nao encontrado')
            || (str_contains($friendlyMessage, 'Bin') && str_contains($friendlyMessage, 'OCR') && str_contains($friendlyMessage, 'PATH'));

        $attachment->forceFill([
            'ocr_status' => 'failed',
            'processed_at' => now(),
            'ocr_metadata' => $this->mergeOcrMetadata($attachment, [
                'status' => $missingEngine ? 'missing_engine' : 'failed',
                'finished_at' => now()->toDateTimeString(),
                'engine' => 'ocrmypdf',
                'message' => $friendlyMessage,
            ]),
        ])->save();

        $this->logEvent(
            $attachment,
            $missingEngine ? 'attachment.ocr.missing_engine' : 'attachment.ocr.failed',
            $missingEngine ? 'Motor OCR do anexo nao encontrado' : 'Falha no OCR do anexo',
            $friendlyMessage,
        );
    }

    private function mergeOcrMetadata(GedDocumentAttachment $attachment, array $ocr): array
    {
        return array_merge($attachment->ocr_metadata ?: [], $this->cleanArray($ocr));
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
        $message = $exception?->getMessage() ?: 'Falha desconhecida no OCR do anexo.';

        if (str_contains(strtolower($message), 'timed out') || str_contains(strtolower($message), 'timeout') || str_contains(strtolower($message), 'tempo')) {
            return 'O OCR do anexo excedeu o tempo limite de processamento.';
        }

        if (str_contains($message, 'Binario OCR nao encontrado')) {
            return $message.'. Instale OCRmyPDF/Tesseract/Poppler no servidor ou ajuste as variaveis GED_OCR_*_BIN.';
        }

        if (str_contains($message, 'ocrmypdf')) {
            return 'Falha ao executar OCRmyPDF no anexo. Verifique se OCRmyPDF, Tesseract e os idiomas OCR estao instalados no servidor.';
        }

        return $message;
    }

    private function logEvent(GedDocumentAttachment $attachment, string $type, string $title, ?string $description = null, array $properties = []): void
    {
        GedDocumentEvent::create([
            'tenant_id' => $attachment->document->tenant_id,
            'document_id' => $attachment->document_id,
            'actor_id' => null,
            'event_type' => $type,
            'title' => $title,
            'description' => $description,
            'properties' => array_merge([
                'attachment_id' => $attachment->id,
                'original_filename' => $attachment->original_filename,
            ], $properties) ?: null,
        ]);
    }
}
