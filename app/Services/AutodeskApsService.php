<?php

namespace App\Services;

use App\Models\ProjectDocumentVersion;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class AutodeskApsService
{
    private const BASE_URL = 'https://developer.api.autodesk.com';
    private const CHUNK_SIZE = 5_242_880;

    public function isConfigured(): bool
    {
        return $this->clientId() !== '' && $this->clientSecret() !== '' && $this->bucketKey() !== '';
    }

    /**
     * @return array{access_token: string, expires_in: int}
     */
    public function viewerToken(): array
    {
        return $this->token(['viewables:read']);
    }

    public function bucketKeyName(): string
    {
        return $this->bucketKey();
    }

    public function bucketRegion(): string
    {
        return $this->region();
    }

    /**
     * @return array{bucket: array<string, mixed>|null, objects: array<int, array<string, mixed>>, object_count: int, total_size: int, truncated: bool}
     */
    public function bucketStorageSummary(int $maxObjects = 500): array
    {
        $this->ensureConfigured();

        $token = $this->token(['bucket:read', 'data:read'])['access_token'];
        $bucketKey = $this->bucketKey();
        $bucket = $this->http()
            ->withToken($token)
            ->acceptJson()
            ->get(self::BASE_URL.'/oss/v2/buckets/'.$bucketKey.'/details')
            ->throw()
            ->json();

        $objects = [];
        $nextUrl = self::BASE_URL.'/oss/v2/buckets/'.$bucketKey.'/objects';
        $params = ['limit' => min(100, max(1, $maxObjects))];

        while ($nextUrl && count($objects) < $maxObjects) {
            $response = $this->http()
                ->withToken($token)
                ->acceptJson()
                ->get($nextUrl, $params)
                ->throw()
                ->json();

            foreach (($response['items'] ?? []) as $item) {
                $objects[] = $this->normalizeObjectItem($item);

                if (count($objects) >= $maxObjects) {
                    break;
                }
            }

            $nextUrl = $response['next'] ?? null;
            $params = [];

            if (! is_string($nextUrl) || $nextUrl === '') {
                $nextUrl = null;
            } elseif (str_starts_with($nextUrl, '/')) {
                $nextUrl = self::BASE_URL.$nextUrl;
            }
        }

        return [
            'bucket' => $bucket,
            'objects' => $objects,
            'object_count' => count($objects),
            'total_size' => array_sum(array_map(fn (array $object): int => (int) ($object['size'] ?? 0), $objects)),
            'truncated' => count($objects) >= $maxObjects,
        ];
    }

    public function submitVersion(ProjectDocumentVersion $version): ProjectDocumentVersion
    {
        $this->ensureConfigured();

        if ($version->aps_object_id && $version->aps_urn) {
            $this->startTranslation($version->aps_urn);
            $version->forceFill([
                'derivative_status' => 'queued',
                'submitted_to_aps_at' => now(),
                'processed_at' => null,
            ])->save();

            return $version->refresh();
        }

        $absolutePath = Storage::disk('public')->path($version->file_path);

        if (! is_file($absolutePath)) {
            throw new RuntimeException('Arquivo local nao encontrado para envio a APS.');
        }

        $this->ensureBucketExists();

        $objectKey = $this->objectKey($version);
        $object = $this->uploadObject($objectKey, $absolutePath, $version->mime_type ?: 'application/octet-stream');
        $objectId = $object['objectId'] ?? "urn:adsk.objects:os.object:{$this->bucketKey()}/{$objectKey}";
        $urn = $this->urnify($objectId);

        $this->startTranslation($urn);

        $version->forceFill([
            'aps_object_id' => $objectId,
            'aps_urn' => $urn,
            'derivative_status' => 'queued',
            'submitted_to_aps_at' => now(),
            'processed_at' => null,
        ])->save();

        return $version->refresh();
    }

    public function deleteVersionFromAps(ProjectDocumentVersion $version): ProjectDocumentVersion
    {
        $this->ensureConfigured();

        $token = $this->token(['data:read', 'data:write'])['access_token'];

        if ($version->aps_urn) {
            $this->ignoreNotFound(
                $this->http()
                    ->withToken($token)
                    ->acceptJson()
                    ->delete(self::BASE_URL.'/modelderivative/v2/designdata/'.$version->aps_urn.'/manifest')
            );
        }

        $objectKey = $this->objectKeyFromObjectId($version->aps_object_id);

        if ($objectKey) {
            $this->ignoreNotFound(
                $this->http()
                    ->withToken($token)
                    ->acceptJson()
                    ->delete(self::BASE_URL.'/oss/v2/buckets/'.$this->bucketKey().'/objects/'.rawurlencode($objectKey))
            );
        }

        $version->forceFill([
            'aps_object_id' => null,
            'aps_urn' => null,
            'derivative_status' => 'not_submitted',
            'submitted_to_aps_at' => null,
            'processed_at' => null,
        ])->save();

        return $version->refresh();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function manifest(ProjectDocumentVersion $version): ?array
    {
        if (! $version->aps_urn) {
            return null;
        }

        try {
            $response = $this->http()
                ->withToken($this->token()['access_token'])
                ->acceptJson()
                ->get(self::BASE_URL.'/modelderivative/v2/designdata/'.$version->aps_urn.'/manifest');

            if ($response->status() === 404) {
                return null;
            }

            return $response->throw()->json();
        } catch (RequestException $exception) {
            if ($exception->response?->status() === 404) {
                return null;
            }

            throw $exception;
        }
    }

    public function syncManifestStatus(ProjectDocumentVersion $version): ProjectDocumentVersion
    {
        $manifest = $this->manifest($version);

        if (! $manifest) {
            return $version;
        }

        $status = (string) ($manifest['status'] ?? '');

        if ($status === 'success') {
            $version->forceFill([
                'derivative_status' => 'ready',
                'processed_at' => $version->processed_at ?? now(),
            ])->save();
        } elseif ($status === 'failed' || $status === 'timeout') {
            $version->forceFill([
                'derivative_status' => 'failed',
                'processed_at' => now(),
            ])->save();
        } elseif (in_array($status, ['pending', 'inprogress'], true)) {
            $version->forceFill([
                'derivative_status' => 'processing',
            ])->save();
        }

        return $version->refresh();
    }

    /**
     * @param array<int, string>|null $scopes
     * @return array{access_token: string, expires_in: int, token_type?: string}
     */
    private function token(?array $scopes = null): array
    {
        $this->ensureConfigured();

        $scope = implode(' ', $scopes ?: $this->scopes());

        return $this->http()
            ->asForm()
            ->withBasicAuth($this->clientId(), $this->clientSecret())
            ->post(self::BASE_URL.'/authentication/v2/token', [
                'grant_type' => 'client_credentials',
                'scope' => $scope,
            ])
            ->throw()
            ->json();
    }

    private function ensureBucketExists(): void
    {
        $token = $this->token()['access_token'];
        $bucketKey = $this->bucketKey();

        $details = $this->http()
            ->withToken($token)
            ->acceptJson()
            ->get(self::BASE_URL.'/oss/v2/buckets/'.$bucketKey.'/details');

        if ($details->successful()) {
            return;
        }

        if ($details->status() !== 404) {
            $details->throw();
        }

        $created = $this->http()
            ->withToken($token)
            ->withHeaders(['x-ads-region' => $this->region()])
            ->acceptJson()
            ->post(self::BASE_URL.'/oss/v2/buckets', [
                'bucketKey' => $bucketKey,
                'policyKey' => 'persistent',
            ]);

        if (! in_array($created->status(), [200, 201, 409], true)) {
            $created->throw();
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function uploadObject(string $objectKey, string $absolutePath, string $contentType): array
    {
        $token = $this->token()['access_token'];
        $bucketKey = $this->bucketKey();
        $size = filesize($absolutePath);
        $totalParts = max(1, (int) ceil($size / self::CHUNK_SIZE));
        $part = 1;
        $uploadKey = null;
        $urls = [];
        $handle = fopen($absolutePath, 'rb');

        if (! $handle) {
            throw new RuntimeException('Nao foi possivel abrir o arquivo local para envio a APS.');
        }

        try {
            while (! feof($handle)) {
                if ($urls === []) {
                    $remainingParts = min(25, $totalParts - $part + 1);
                    $query = [
                        'parts' => $remainingParts,
                        'firstPart' => $part,
                        'minutesExpiration' => 10,
                    ];

                    if ($uploadKey) {
                        $query['uploadKey'] = $uploadKey;
                    }

                    $signed = $this->http()
                        ->withToken($token)
                        ->acceptJson()
                        ->get(self::BASE_URL.'/oss/v2/buckets/'.$bucketKey.'/objects/'.rawurlencode($objectKey).'/signeds3upload', $query)
                        ->throw()
                        ->json();

                    $uploadKey = $signed['uploadKey'] ?? $uploadKey;
                    $urls = $signed['urls'] ?? [];
                }

                $chunk = fread($handle, self::CHUNK_SIZE);

                if ($chunk === '' || $chunk === false) {
                    break;
                }

                $url = array_shift($urls);

                if (! $url) {
                    throw new RuntimeException('APS nao retornou URL assinada para upload.');
                }

                $this->http()
                    ->withBody($chunk, 'application/octet-stream')
                    ->put($url)
                    ->throw();

                $part++;
            }
        } finally {
            fclose($handle);
        }

        if (! $uploadKey) {
            throw new RuntimeException('APS nao retornou chave de conclusao do upload.');
        }

        return $this->http()
            ->withToken($token)
            ->withHeaders(['x-ads-meta-Content-Type' => $contentType])
            ->acceptJson()
            ->post(self::BASE_URL.'/oss/v2/buckets/'.$bucketKey.'/objects/'.rawurlencode($objectKey).'/signeds3upload', [
                'uploadKey' => $uploadKey,
            ])
            ->throw()
            ->json();
    }

    private function startTranslation(string $urn): void
    {
        $this->http()
            ->withToken($this->token()['access_token'])
            ->acceptJson()
            ->post(self::BASE_URL.'/modelderivative/v2/designdata/job', [
                'input' => [
                    'urn' => $urn,
                ],
                'output' => [
                    'formats' => [
                        [
                            'type' => 'svf2',
                            'views' => ['2d', '3d'],
                        ],
                    ],
                ],
            ])
            ->throw();
    }

    private function http(): PendingRequest
    {
        return Http::withOptions([
            'verify' => $this->sslVerifyOption(),
        ])->timeout(60);
    }

    /**
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    private function normalizeObjectItem(array $item): array
    {
        return [
            'object_key' => $item['objectKey'] ?? $item['object_key'] ?? null,
            'object_id' => $item['objectId'] ?? $item['object_id'] ?? null,
            'sha1' => $item['sha1'] ?? null,
            'size' => (int) ($item['size'] ?? $item['contentLength'] ?? 0),
            'location' => $item['location'] ?? null,
        ];
    }

    private function ignoreNotFound(Response $response): void
    {
        if ($response->status() === 404) {
            return;
        }

        $response->throw();
    }

    private function sslVerifyOption(): bool|string
    {
        $verifySsl = filter_var(config('services.autodesk_aps.verify_ssl', true), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        if ($verifySsl === false) {
            return false;
        }

        $caBundle = trim((string) config('services.autodesk_aps.ca_bundle'));

        return $caBundle !== '' ? $caBundle : true;
    }

    private function urnify(string $id): string
    {
        return rtrim(strtr(base64_encode($id), '+/', '-_'), '=');
    }

    private function objectKey(ProjectDocumentVersion $version): string
    {
        $fileName = $version->stored_name ?: $version->original_name;
        $extension = pathinfo((string) $fileName, PATHINFO_EXTENSION);
        $name = pathinfo((string) $fileName, PATHINFO_FILENAME);
        $safeName = Str::slug($name, '_') ?: 'project_file';

        return 'tenant-'.$version->tenant_id.'-project-version-'.$version->id.'-'.$safeName.($extension ? '.'.mb_strtolower($extension) : '');
    }

    private function objectKeyFromObjectId(?string $objectId): ?string
    {
        if (! $objectId || ! str_contains($objectId, '/')) {
            return null;
        }

        return rawurldecode((string) Str::of($objectId)->afterLast('/'));
    }

    private function ensureConfigured(): void
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('Configure AUTODESK_APS_CLIENT_ID, AUTODESK_APS_CLIENT_SECRET e AUTODESK_APS_BUCKET_KEY no .env.');
        }
    }

    private function clientId(): string
    {
        return trim((string) config('services.autodesk_aps.client_id'));
    }

    private function clientSecret(): string
    {
        return trim((string) config('services.autodesk_aps.client_secret'));
    }

    private function bucketKey(): string
    {
        return trim((string) config('services.autodesk_aps.bucket_key'));
    }

    private function region(): string
    {
        return strtoupper(trim((string) config('services.autodesk_aps.region', 'US')));
    }

    /**
     * @return array<int, string>
     */
    private function scopes(): array
    {
        return config('services.autodesk_aps.scopes', []);
    }
}
