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

        if (in_array($path, ['/createdocument', '/api/v1/documents'], true)) {
            $path = '/draftdocument';
        }

        if ($baseUrl === '' || $apiKey === '') {
            throw new RuntimeException('OpenSign não está configurado. Defina OPENSIGN_BASE_URL e OPENSIGN_API_KEY.');
        }

        $pdf = file_get_contents($absolutePdfPath);

        if ($pdf === false) {
            throw new RuntimeException('Não foi possível ler o PDF do RDO para enviá-lo ao OpenSign.');
        }

        $payload = [
            'file' => base64_encode($pdf),
            'title' => $request->title,
            'note' => 'Assinatura digital do RDO '.$request->rdo?->code,
            'description' => 'Documento gerado pelo Deming para assinatura dos responsáveis.',
            'timeToCompleteDays' => (int) config('signatures.opensign.time_to_complete_days', 15),
            'send_email' => true,
            'sendInOrder' => false,
            'enableOTP' => false,
            'merge_certificate' => true,
            'notify_on_signatures' => true,
            'signers' => $request->signers
                ->values()
                ->map(fn ($signer, int $index) => [
                    'name' => $signer->name,
                    'email' => $signer->email,
                    'role' => $signer->role,
                    'signer_role' => 'signer',
                    'widgets' => [$this->signatureWidget($index)],
                ])
                ->all(),
        ];

        $response = Http::withHeaders(['x-api-token' => $apiKey])
            ->acceptJson()
            ->asJson()
            ->timeout(90)
            ->withOptions(['verify' => (bool) config('signatures.opensign.verify_ssl')])
            ->post($baseUrl.$path, $payload);

        if ($response->failed()) {
            $message = (string) ($response->json('error') ?? $response->json('message') ?? strip_tags($response->body()));
            $message = Str::of($message)->squish()->limit(800)->toString();

            throw new RuntimeException(sprintf(
                'OpenSign recusou a solicitação (HTTP %d): %s',
                $response->status(),
                $message !== '' ? $message : 'resposta sem detalhes'
            ));
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

    private function signatureWidget(int $index): array
    {
        $column = $index % 2;
        $row = intdiv($index, 2);

        return [
            'name' => 'assinatura_'.($index + 1),
            'type' => 'signature',
            'page' => 1,
            'x' => 45 + ($column * 280),
            'y' => 690 + ($row * 65),
            'w' => 220,
            'h' => 45,
        ];
    }
}
