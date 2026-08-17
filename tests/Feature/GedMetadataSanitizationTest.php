<?php

namespace Tests\Feature;

use App\Models\GedDocument;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class GedMetadataSanitizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_document_metadata_is_sanitized_before_it_reaches_postgres(): void
    {
        $tenant = Tenant::create([
            'slug' => 'ged-metadata-test',
            'name' => 'GED metadata test',
            'plan' => 'starter',
            'status' => 'active',
        ]);

        $document = GedDocument::create([
            'tenant_id' => $tenant->id,
            'title' => 'Documento com metadados UTF-16',
            'original_filename' => 'documento.pdf',
            'mime_type' => 'application/pdf',
            'extension' => 'pdf',
            'size_bytes' => 100,
            'checksum' => hash('sha256', 'documento-com-metadados'),
            'storage_disk' => 'public',
            'original_path' => 'ged/test/documento.pdf',
            'metadata' => [
                'original_file_metadata' => [
                    'pdf:Producer' => "Adobe\0 PDF\0 Library",
                ],
            ],
        ]);

        $storedMetadata = DB::table('ged_documents')
            ->where('id', $document->id)
            ->value('metadata');

        $this->assertStringNotContainsString('\\u0000', $storedMetadata);
        $this->assertSame(
            'Adobe PDF Library',
            $document->fresh()->metadata['original_file_metadata']['pdf:Producer'],
        );
        $this->assertSame(1, GedDocument::query()
            ->where('metadata->original_file_metadata->pdf:Producer', 'Adobe PDF Library')
            ->count());
    }
}
