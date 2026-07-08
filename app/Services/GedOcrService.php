<?php

namespace App\Services;

use App\Models\GedDocument;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

class GedOcrService
{
    private ?float $deadlineAt = null;

    public function process(GedDocument $document): array
    {
        $disk = Storage::disk($document->storage_disk ?: 'public');

        if (! $disk->exists($document->original_path)) {
            throw new RuntimeException('Arquivo original não encontrado para processamento OCR.');
        }

        $mimeType = (string) $document->mime_type;
        $extension = strtolower((string) $document->extension);
        $isPdf = $mimeType === 'application/pdf' || $extension === 'pdf';
        $isImage = str_starts_with($mimeType, 'image/') || in_array($extension, ['jpg', 'jpeg', 'png', 'tif', 'tiff', 'webp', 'bmp'], true);

        if (! $isPdf && ! $isImage) {
            return [
                'text' => '',
                'archive_path' => null,
                'page_count' => null,
                'engine' => 'none',
                'message' => 'Tipo de arquivo não suportado para OCR automático.',
            ];
        }

        $workDir = storage_path('app/private/ged-ocr/'.$document->id.'-'.Str::uuid());
        File::ensureDirectoryExists($workDir);
        $inputPath = $workDir.'/input'.($extension ? ".{$extension}" : '');
        File::put($inputPath, $disk->get($document->original_path));
        $this->deadlineAt = microtime(true) + max(60, (int) config('ged.ocr.timeout', 300));

        try {
            return $this->processWithOcrmypdf($document, $inputPath, $workDir, $isPdf);
        } catch (RuntimeException|ProcessFailedException $exception) {
            if ($isPdf) {
                $tesseractText = $this->extractPdfTextWithTesseract($inputPath, $workDir);

                if (trim($tesseractText) !== '') {
                    return [
                        'text' => $tesseractText,
                        'archive_path' => null,
                        'page_count' => $this->countPdfPages($inputPath, $workDir),
                        'engine' => 'pdftoppm+tesseract',
                        'message' => 'Texto extraído com Tesseract local. OCRmyPDF/Ghostscript não ficou disponível: '.$exception->getMessage(),
                    ];
                }

                $fallbackText = $this->extractSearchablePdfText($inputPath, $workDir);

                if (trim($fallbackText) !== '') {
                    return [
                        'text' => $fallbackText,
                        'archive_path' => null,
                        'page_count' => $this->countPdfPages($inputPath, $workDir),
                        'engine' => 'pdftotext',
                        'message' => 'Texto extraído de PDF pesquisável. OCRmyPDF não ficou disponível: '.$exception->getMessage(),
                    ];
                }
            }

            if ($isImage) {
                $tesseractText = $this->extractImageTextWithTesseract($inputPath, $workDir);

                if (trim($tesseractText) !== '') {
                    return [
                        'text' => $tesseractText,
                        'archive_path' => null,
                        'page_count' => 1,
                        'engine' => 'tesseract',
                        'message' => 'Texto extraído com Tesseract local. OCRmyPDF/Ghostscript não ficou disponível: '.$exception->getMessage(),
                    ];
                }
            }

            throw $exception;
        } finally {
            $this->deadlineAt = null;
            File::deleteDirectory($workDir);
        }
    }

    private function processWithOcrmypdf(GedDocument $document, string $inputPath, string $workDir, bool $isPdf): array
    {
        $outputPdf = $workDir.'/archive.pdf';
        $sidecar = $workDir.'/sidecar.txt';

        $command = [
            (string) config('ged.ocr.binaries.ocrmypdf', 'ocrmypdf'),
            '--language',
            (string) config('ged.ocr.language', 'por+eng'),
            '--sidecar',
            $sidecar,
            '--output-type',
            (string) config('ged.ocr.output_type', 'pdfa'),
            '--jobs',
            '1',
        ];

        if ((bool) config('ged.ocr.deskew', true)) {
            $command[] = '--deskew';
        }

        if ((bool) config('ged.ocr.rotate_pages', true)) {
            $command[] = '--rotate-pages';
        }

        $mode = (string) config('ged.ocr.mode', 'skip');

        if ($mode === 'force') {
            $command[] = '--force-ocr';
        } elseif ($mode === 'redo') {
            $command[] = '--redo-ocr';
        } else {
            $command[] = '--skip-text';
        }

        $maxPages = (int) config('ged.ocr.max_pages', 25);

        if ($isPdf && $maxPages > 0) {
            $command[] = '--pages';
            $command[] = '1-'.$maxPages;
        }

        $command[] = $inputPath;
        $command[] = $outputPdf;

        $this->run($command);

        $text = File::exists($sidecar) ? trim((string) File::get($sidecar)) : '';

        if ($text === '') {
            $text = trim($this->extractSearchablePdfText($outputPdf, $workDir));
        }

        $archivePath = 'ged/'.$document->tenant_id.'/archive/'.now()->format('Y/m').'/'.$document->id.'-'.Str::uuid().'.pdf';
        Storage::disk($document->storage_disk ?: 'public')->put($archivePath, File::get($outputPdf));

        return [
            'text' => $text,
            'archive_path' => $archivePath,
            'page_count' => $this->countPdfPages($outputPdf, $workDir) ?: ($isPdf ? $this->countPdfPages($inputPath, $workDir) : 1),
            'engine' => 'ocrmypdf',
            'message' => 'OCR processado com OCRmyPDF/Tesseract.',
        ];
    }

    private function extractSearchablePdfText(string $pdfPath, string $workDir): string
    {
        $output = $workDir.'/pdftotext.txt';

        try {
            $this->run([
                (string) config('ged.ocr.binaries.pdftotext', 'pdftotext'),
                '-layout',
                '-enc',
                'UTF-8',
                $pdfPath,
                $output,
            ]);
        } catch (RuntimeException|ProcessFailedException) {
            return '';
        }

        return File::exists($output) ? trim((string) File::get($output)) : '';
    }

    private function extractPdfTextWithTesseract(string $pdfPath, string $workDir): string
    {
        $prefix = $workDir.'/page';
        $maxPages = max(1, (int) config('ged.ocr.max_pages', 25));

        try {
            $this->run([
                (string) config('ged.ocr.binaries.pdftoppm', 'pdftoppm'),
                '-f',
                '1',
                '-l',
                (string) $maxPages,
                '-r',
                '200',
                '-png',
                $pdfPath,
                $prefix,
            ]);
        } catch (RuntimeException|ProcessFailedException) {
            return '';
        }

        $texts = [];

        foreach (collect(File::files($workDir))->filter(fn ($file) => str_starts_with($file->getFilename(), 'page-') && $file->getExtension() === 'png')->sortBy(fn ($file) => $file->getFilename()) as $image) {
            $this->assertWithinDeadline();

            $text = $this->extractImageTextWithTesseract($image->getPathname(), $workDir);

            if (trim($text) !== '') {
                $texts[] = trim($text);
            }
        }

        return trim(implode("\n\n", $texts));
    }

    private function extractImageTextWithTesseract(string $imagePath, string $workDir): string
    {
        $outputBase = $workDir.'/tesseract-'.Str::uuid();
        $command = [
            (string) config('ged.ocr.binaries.tesseract', 'tesseract'),
            $imagePath,
            $outputBase,
            '-l',
            (string) config('ged.ocr.language', 'por+eng'),
            '--psm',
            '1',
        ];

        $tessdataDir = config('ged.ocr.tessdata_dir');

        if ($tessdataDir) {
            $command[] = '--tessdata-dir';
            $command[] = (string) $tessdataDir;
        }

        try {
            $this->run($command);
        } catch (RuntimeException|ProcessFailedException) {
            return '';
        }

        $output = $outputBase.'.txt';

        return File::exists($output) ? trim((string) File::get($output)) : '';
    }

    private function countPdfPages(string $pdfPath, string $workDir): ?int
    {
        $output = $workDir.'/pdfinfo.txt';

        try {
            $process = $this->run([
                (string) config('ged.ocr.binaries.pdfinfo', 'pdfinfo'),
                $pdfPath,
            ]);
        } catch (RuntimeException|ProcessFailedException) {
            return null;
        }

        File::put($output, $process->getOutput());

        if (preg_match('/^Pages:\s+(\d+)/mi', $process->getOutput(), $matches)) {
            return (int) $matches[1];
        }

        return null;
    }

    private function run(array $command): Process
    {
        $this->assertWithinDeadline();
        $this->ensureBinaryIsAvailable((string) $command[0]);

        $process = new Process($command);
        $process->setTimeout($this->remainingTimeoutSeconds());
        $process->run();

        if (! $process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }

        return $process;
    }

    private function assertWithinDeadline(): void
    {
        if ($this->deadlineAt !== null && microtime(true) >= $this->deadlineAt) {
            throw new RuntimeException('O OCR excedeu o tempo limite global de processamento do documento.');
        }
    }

    private function remainingTimeoutSeconds(): int
    {
        if ($this->deadlineAt === null) {
            return max(60, (int) config('ged.ocr.timeout', 300));
        }

        return max(1, (int) ceil($this->deadlineAt - microtime(true)));
    }

    private function ensureBinaryIsAvailable(string $binary): void
    {
        if (str_contains($binary, '/') || str_contains($binary, '\\')) {
            if (is_file($binary)) {
                return;
            }

            throw new RuntimeException("Binário OCR não encontrado no caminho configurado: {$binary}");
        }

        $process = PHP_OS_FAMILY === 'Windows'
            ? new Process(['where.exe', $binary])
            : new Process(['sh', '-lc', 'command -v '.escapeshellarg($binary)]);

        $process->setTimeout(10);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException("Binário OCR não encontrado no PATH: {$binary}");
        }
    }
}
