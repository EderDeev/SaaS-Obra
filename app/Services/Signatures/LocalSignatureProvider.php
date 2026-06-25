<?php

namespace App\Services\Signatures;

use App\Models\RdoSignatureRequest;

class LocalSignatureProvider implements SignatureProviderInterface
{
    public function createRequest(RdoSignatureRequest $request, string $absolutePdfPath): array
    {
        $requestId = 'local-rdo-'.$request->rdo_diario_id.'-'.now()->format('YmdHis');

        return [
            'provider_request_id' => $requestId,
            'provider_document_id' => $requestId,
            'signing_url' => route('tenant.diario-obra.rdo.pdf', [$request->tenant->slug, $request->rdo_diario_id]),
            'signers' => $request->signers
                ->values()
                ->map(fn ($signer) => [
                    'email' => $signer->email,
                    'provider_signer_id' => 'local-signer-'.$signer->id,
                    'status' => 'sent',
                    'signing_url' => route('tenant.diario-obra.rdo.pdf', [$request->tenant->slug, $request->rdo_diario_id]),
                ])
                ->all(),
            'raw' => [
                'driver' => 'local',
                'pdf' => $absolutePdfPath,
            ],
        ];
    }
}
