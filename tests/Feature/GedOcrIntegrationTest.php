<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\GedDocument;
use App\Models\Tenant;
use App\Services\GedOcrService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class GedOcrIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_real_ocr_engine_extracts_text_from_a_pdf(): void
    {
        $probe = new Process(['ocrmypdf', '--version']);
        $probe->run();

        if (! $probe->isSuccessful()) {
            $this->markTestSkipped('OCRmyPDF nao esta instalado neste ambiente.');
        }

        Storage::fake('public');
        $tenant = Tenant::create([
            'slug' => 'ocr-integracao',
            'name' => 'OCR Integracao',
            'plan' => 'starter',
            'status' => 'active',
        ]);
        $contract = Contract::create([
            'tenant_id' => $tenant->id,
            'code' => 'CT-OCR',
            'name' => 'Contrato OCR',
            'status' => 'active',
        ]);
        $pdf = Pdf::loadHTML('<html><body><h1>Relatorio de medicao OCR</h1><p>Documento pesquisavel para o teste de integracao.</p></body></html>')->output();
        $path = 'ged/'.$tenant->id.'/ocr-integracao.pdf';
        Storage::disk('public')->put($path, $pdf);
        $document = GedDocument::create([
            'tenant_id' => $tenant->id,
            'contract_id' => $contract->id,
            'title' => 'Documento OCR',
            'document_number' => '001/2026',
            'sequence_year' => 2026,
            'sequence_number' => 1,
            'status' => 'processing',
            'original_filename' => 'ocr-integracao.pdf',
            'mime_type' => 'application/pdf',
            'extension' => 'pdf',
            'size_bytes' => strlen($pdf),
            'checksum' => hash('sha256', $pdf),
            'storage_disk' => 'public',
            'original_path' => $path,
        ]);

        $result = app(GedOcrService::class)->process($document);

        $this->assertStringContainsString('Relatorio de medicao OCR', $result['text']);
        $this->assertGreaterThanOrEqual(1, $result['page_count']);
        $this->assertNotEmpty($result['engine']);
        $this->assertNotEmpty($result['archive_path']);
        Storage::disk('public')->assertExists($result['archive_path']);
    }
}
