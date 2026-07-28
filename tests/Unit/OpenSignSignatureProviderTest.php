<?php

namespace Tests\Unit;

use App\Models\RdoSignatureRequest;
use App\Services\Signatures\OpenSignSignatureProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OpenSignSignatureProviderTest extends TestCase
{
    public function test_document_lookup_keeps_primary_response_when_cloud_fallback_is_unavailable(): void
    {
        config([
            'signatures.opensign.base_url' => 'https://opensign.test/api/v1.2',
            'signatures.opensign.api_key' => 'test-key',
        ]);

        Http::fake(function ($request) {
            if ($request->url() === 'https://opensign.test/api/v1.2/document/doc-123') {
                return Http::response([
                    'objectId' => 'doc-123',
                    'status' => 'completed',
                ]);
            }

            throw new ConnectionException('Cloud fallback unavailable.');
        });

        $document = app(OpenSignSignatureProvider::class)->getDocument('doc-123');

        $this->assertSame('doc-123', $document['objectId']);
        $this->assertSame('completed', $document['status']);
    }

    public function test_signing_link_lookup_returns_fallback_when_endpoint_is_unavailable(): void
    {
        config([
            'signatures.opensign.base_url' => 'https://opensign.test/api/v1.2',
            'signatures.opensign.api_key' => 'test-key',
        ]);

        Http::fake(fn () => throw new ConnectionException('Signing links unavailable.'));

        $signatureRequest = new RdoSignatureRequest();
        $signatureRequest->setRelation('signers', collect());

        $links = app(OpenSignSignatureProvider::class)->getSigningLinks('doc-123', $signatureRequest);

        $this->assertSame([], $links);
    }
}
