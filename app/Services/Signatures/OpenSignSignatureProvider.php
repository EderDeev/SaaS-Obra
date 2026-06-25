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
        $request->loadMissing(['rdo.contract', 'signers']);
        $baseUrl = rtrim((string) config('signatures.opensign.base_url'), '/');
        $apiKey = (string) config('signatures.opensign.api_key');
        $path = '/'.ltrim((string) config('signatures.opensign.create_request_path'), '/');

        if (in_array($path, ['/draftdocument', '/api/v1/documents'], true)) {
            $path = '/createdocument';
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
            'email_subject' => 'Assinatura solicitada: {{document_title}}',
            'email_body' => $this->signatureEmailBody(),
            'sendInOrder' => false,
            'hide_signer_signing_links' => false,
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
                    'widgets' => [$this->signatureWidget($signer->role, $index)],
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
        $providerDocumentId = Arr::get($data, 'document_id')
            ?? Arr::get($data, 'document.objectId')
            ?? $providerRequestId;
        $providerSigners = $this->extractSigners($data);

        if ($providerDocumentId) {
            $providerSigners = $this->fetchSigningLinks(
                $baseUrl,
                $apiKey,
                (string) $providerDocumentId,
                $request,
                $providerSigners,
            );
        }

        return [
            'provider_request_id' => $providerRequestId ? (string) $providerRequestId : null,
            'provider_document_id' => $providerDocumentId ? (string) $providerDocumentId : null,
            'signing_url' => count($providerSigners) === 1 ? ($providerSigners[0]['signing_url'] ?? null) : null,
            'signers' => $providerSigners,
            'raw' => $data,
        ];
    }

    private function fetchSigningLinks(
        string $baseUrl,
        string $apiKey,
        string $documentId,
        RdoSignatureRequest $request,
        array $fallbackSigners,
    ): array {
        $response = Http::withHeaders(['x-api-token' => $apiKey])
            ->acceptJson()
            ->timeout(30)
            ->withOptions(['verify' => (bool) config('signatures.opensign.verify_ssl')])
            ->get($baseUrl.'/signinglinks/'.rawurlencode($documentId));

        if ($response->failed()) {
            return $fallbackSigners;
        }

        $links = $this->extractSigners($response->json() ?? []);

        if ($links === []) {
            return $fallbackSigners;
        }

        return collect($request->signers)
            ->values()
            ->map(function ($signer, int $index) use ($links, $fallbackSigners): array {
                $email = Str::lower((string) $signer->email);
                $link = collect($links)->firstWhere('email', $email) ?? ($links[$index] ?? null);
                $fallback = collect($fallbackSigners)->firstWhere('email', $email) ?? [];

                return [
                    'email' => $email,
                    'provider_signer_id' => $link['provider_signer_id'] ?? $fallback['provider_signer_id'] ?? null,
                    'status' => $link['status'] ?? $fallback['status'] ?? 'sent',
                    'signing_url' => $link['signing_url'] ?? $fallback['signing_url'] ?? null,
                    'raw' => $link['raw'] ?? $fallback['raw'] ?? $link,
                ];
            })
            ->all();
    }

    private function extractSigners(array $data): array
    {
        $found = [];
        $walk = function (mixed $node, ?string $parentKey = null) use (&$walk, &$found): void {
            if (! is_array($node)) {
                return;
            }

            $email = Str::lower((string) ($node['email'] ?? $node['Email'] ?? $node['signer_email'] ?? ''));
            $url = $node['signing_url']
                ?? $node['signingUrl']
                ?? $node['signing_link']
                ?? $node['signingLink']
                ?? null;

            if (! $url && ($email !== '' || in_array($parentKey, ['signinglinks', 'signing_links', 'links', 'signers'], true))) {
                $url = $node['url'] ?? $node['URL'] ?? null;
            }

            if ($url) {
                $found[] = [
                    'email' => $email,
                    'provider_signer_id' => $node['id'] ?? $node['objectId'] ?? $node['signer_id'] ?? null,
                    'status' => $node['status'] ?? 'sent',
                    'signing_url' => (string) $url,
                    'raw' => $node,
                ];
            }

            foreach ($node as $key => $value) {
                if (is_string($key) && filter_var($key, FILTER_VALIDATE_EMAIL) && is_string($value) && filter_var($value, FILTER_VALIDATE_URL)) {
                    $found[] = [
                        'email' => Str::lower($key),
                        'provider_signer_id' => null,
                        'status' => 'sent',
                        'signing_url' => $value,
                        'raw' => [$key => $value],
                    ];
                } elseif (is_array($value)) {
                    $walk($value, is_string($key) ? Str::lower($key) : $parentKey);
                }
            }
        };

        $walk($data);

        return collect($found)
            ->filter(fn (array $signer) => filter_var($signer['signing_url'], FILTER_VALIDATE_URL))
            ->unique(fn (array $signer) => $signer['email'].'|'.$signer['signing_url'])
            ->values()
            ->all();
    }

    private function signatureEmailBody(): string
    {
        return <<<'HTML'
<div style="margin:0;background:#f4f6fb;padding:28px 12px;font-family:Arial,Helvetica,sans-serif;color:#111827">
  <div style="max-width:640px;margin:0 auto;background:#ffffff;border:1px solid #e5e7eb;border-radius:14px;overflow:hidden">
    <div style="background:#0b5fff;color:#ffffff;padding:22px 26px">
      <div style="font-size:12px;font-weight:700;letter-spacing:.08em;text-transform:uppercase">Deming</div>
      <h1 style="margin:10px 0 0;font-size:22px;line-height:1.3">Assinatura de RDO solicitada</h1>
    </div>
    <div style="padding:26px">
      <p style="margin:0 0 16px;font-size:15px;line-height:1.6">Olá, {{receiver_name}}.</p>
      <p style="margin:0 0 20px;font-size:15px;line-height:1.6">
        Você foi indicado como responsável pela assinatura do documento <strong>{{document_title}}</strong>.
      </p>
      <p style="margin:0 0 24px;font-size:14px;line-height:1.6;color:#667085">
        A solicitação expira em {{expiry_date}}. Use o botão abaixo para acessar a plataforma de assinatura.
      </p>
      <a href="{{signing_url}}" style="display:inline-block;background:#0b5fff;color:#ffffff;text-decoration:none;border-radius:9px;padding:12px 18px;font-size:14px;font-weight:700">
        Assinar documento
      </a>
    </div>
  </div>
</div>
HTML;
    }

    public function getDocument(string $documentId): array
    {
        $baseUrl = rtrim((string) config('signatures.opensign.base_url'), '/');
        $apiKey = (string) config('signatures.opensign.api_key');

        if ($baseUrl === '' || $apiKey === '') {
            return [];
        }

        $response = Http::withHeaders(['x-api-token' => $apiKey])
            ->acceptJson()
            ->timeout(30)
            ->withOptions(['verify' => (bool) config('signatures.opensign.verify_ssl')])
            ->get($baseUrl.'/document/'.rawurlencode($documentId));

        return $response->successful() ? ($response->json() ?? []) : [];
    }

    private function signatureWidget(string $role, int $index): array
    {
        $positions = [
            'construtora' => ['x' => 51, 'name' => 'assinatura_construtora'],
            'gerenciadora' => ['x' => 241, 'name' => 'assinatura_gerenciadora'],
            'cliente' => ['x' => 431, 'name' => 'assinatura_cliente'],
        ];
        $fallbackRoles = ['construtora', 'gerenciadora', 'cliente'];
        $position = $positions[$role] ?? $positions[$fallbackRoles[$index] ?? 'cliente'];

        return [
            'name' => $position['name'],
            'type' => 'signature',
            'page' => 1,
            'x' => $position['x'],
            'y' => 710,
            'w' => 112,
            'h' => 34,
        ];
    }
}
