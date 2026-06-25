<?php

namespace App\Services\Signatures;

use App\Models\RdoSignatureRequest;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class OpenSignSignatureProvider implements SignatureProviderInterface
{
    public function createRequest(RdoSignatureRequest $request, string $absolutePdfPath): array
    {
        $baseUrl = rtrim((string) config('signatures.opensign.base_url'), '/');
        $apiKey = (string) config('signatures.opensign.api_key');
        $path = '/'.ltrim((string) config('signatures.opensign.create_request_path'), '/');

        if ($baseUrl === '' || $apiKey === '') {
            throw new RuntimeException('OpenSign não está configurado. Defina OPENSIGN_BASE_URL e OPENSIGN_API_KEY.');
        }

        $payload = [
            'title' => $request->title,
            'external_id' => 'rdo-signature-'.$request->id,
            'callback_url' => url('/api/webhooks/opensign'),
            'metadata' => [
                'tenant_id' => $request->tenant_id,
                'rdo_diario_id' => $request->rdo_diario_id,
                'rdo_signature_request_id' => $request->id,
            ],
            'signers' => $request->signers
                ->values()
                ->map(fn ($signer, int $index) => [
                    'name' => $signer->name,
                    'email' => $signer->email,
                    'role' => $signer->role,
                    'order' => $index + 1,
                ])
                ->all(),
        ];

        $response = Http::withHeaders(['x-api-token' => $apiKey])
            ->acceptJson()
            ->asMultipart()
            ->withOptions(['verify' => (bool) config('signatures.opensign.verify_ssl')])
            ->attach('document', file_get_contents($absolutePdfPath), basename($absolutePdfPath))
            ->post($baseUrl.$path, [
                ['name' => 'payload', 'contents' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)],
            ]);

        if ($response->failed()) {
            throw new RuntimeException('OpenSign recusou a solicitação: '.$response->body());
        }

        $data = $response->json() ?? [];
        $providerRequestId = Arr::get($data, 'id')
            ?? Arr::get($data, 'request_id')
            ?? Arr::get($data, 'document_id')
            ?? Arr::get($data, 'objectId');

        return [
            'provider_request_id' => $providerRequestId ? (string) $providerRequestId : null,
            'provider_document_id' => (string) (Arr::get($data, 'document_id') ?? Arr::get($data, 'document.objectId') ?? $providerRequestId),
            'signing_url' => Arr::get($data, 'signing_url') ?? Arr::get($data, 'url') ?? Arr::get($data, 'document.url'),
            'signers' => collect(Arr::get($data, 'signers', []))
                ->map(fn ($signer) => [
                    'email' => Str::lower((string) (Arr::get($signer, 'email') ?? Arr::get($signer, 'Email'))),
                    'provider_signer_id' => Arr::get($signer, 'id') ?? Arr::get($signer, 'objectId'),
                    'status' => Arr::get($signer, 'status') ?? 'sent',
                    'signing_url' => Arr::get($signer, 'signing_url') ?? Arr::get($signer, 'url'),
                    'raw' => $signer,
                ])
                ->filter(fn ($signer) => $signer['email'] !== '')
                ->values()
                ->all(),
            'raw' => $data,
        ];
    }
}
