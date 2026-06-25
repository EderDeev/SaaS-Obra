<?php

namespace App\Services\Signatures;

use App\Models\RdoSignatureRequest;

interface SignatureProviderInterface
{
    /**
     * @return array{
     *     provider_request_id?: string|null,
     *     provider_document_id?: string|null,
     *     signing_url?: string|null,
     *     signers?: array<int, array<string, mixed>>,
     *     raw?: array<string, mixed>
     * }
     */
    public function createRequest(RdoSignatureRequest $request, string $absolutePdfPath): array;
}
