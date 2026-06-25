<?php

namespace App\Services;

use App\Models\RdoDiario;
use App\Models\RdoResponsavel;
use App\Models\RdoSignatureRequest;
use App\Models\RdoSignatureSigner;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Notifications\RdoSignatureRequestedNotification;
use App\Services\Signatures\LocalSignatureProvider;
use App\Services\Signatures\OpenSignSignatureProvider;
use App\Services\Signatures\SignatureProviderInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class RdoSignatureService
{
    public function __construct(private readonly RdoPdfRenderer $pdfRenderer)
    {
    }

    public function createAndSend(Tenant $tenant, RdoDiario $rdo, User $actor): RdoSignatureRequest
    {
        abort_unless((int) $rdo->tenant_id === (int) $tenant->id, 404);
        abort_unless($rdo->status === 'arquivado', 422, 'Somente RDO aprovado e arquivado pode ser enviado para assinatura.');

        $rdo->loadMissing(['contract', 'configuracao.obras']);

        $activeRequest = RdoSignatureRequest::query()
            ->where('tenant_id', $tenant->id)
            ->where('rdo_diario_id', $rdo->id)
            ->whereIn('status', ['draft', 'sent', 'pending', 'completed'])
            ->latest('id')
            ->first();

        abort_if($activeRequest && $activeRequest->status !== 'failed', 422, 'Este RDO já possui uma solicitação de assinatura em andamento ou concluída.');

        $signers = $this->resolveSigners($tenant, $rdo);
        abort_if($signers->isEmpty(), 422, 'Nenhum signatário foi encontrado. Configure responsáveis do RDO ou usuários vinculados às empresas do contrato.');

        $request = DB::transaction(function () use ($tenant, $rdo, $actor, $signers): RdoSignatureRequest {
            $title = sprintf('Assinatura %s - %s', $rdo->code, $rdo->reference_date?->format('d/m/Y'));
            $signatureRequest = RdoSignatureRequest::create([
                'tenant_id' => $tenant->id,
                'rdo_diario_id' => $rdo->id,
                'requested_by_id' => $actor->id,
                'provider' => (string) config('signatures.driver', 'local'),
                'status' => 'draft',
                'title' => $title,
            ]);

            $signers->each(fn (array $signer) => RdoSignatureSigner::create([
                'tenant_id' => $tenant->id,
                'rdo_signature_request_id' => $signatureRequest->id,
                'user_id' => $signer['user_id'] ?? null,
                'empresa_id' => $signer['empresa_id'] ?? null,
                'role' => $signer['role'],
                'name' => $signer['name'],
                'email' => $signer['email'],
                'status' => 'pending',
            ]));

            return $signatureRequest->load(['tenant', 'rdo', 'signers']);
        });

        $pdfPath = $this->storeUnsignedPdf($tenant, $rdo, $request);
        $request->update([
            'unsigned_pdf_path' => $pdfPath,
            'request_payload' => [
                'signers' => $request->signers->map->only(['role', 'name', 'email'])->values()->all(),
            ],
        ]);

        try {
            $providerResponse = $this->provider()->createRequest($request->fresh(['tenant', 'rdo', 'signers']), Storage::disk('public')->path($pdfPath));

            $request->update([
                'provider_request_id' => $providerResponse['provider_request_id'] ?? null,
                'provider_document_id' => $providerResponse['provider_document_id'] ?? null,
                'signing_url' => $providerResponse['signing_url'] ?? null,
                'provider_payload' => $providerResponse['raw'] ?? $providerResponse,
                'status' => 'sent',
                'sent_at' => now(),
                'error_message' => null,
            ]);

            $request = $request->fresh(['tenant', 'rdo', 'signers.user']);
            $this->syncProviderSigners($request, $providerResponse['signers'] ?? []);
            $this->notifySigners($request->fresh(['tenant', 'rdo', 'signers.user']));
        } catch (\Throwable $exception) {
            $request->update([
                'status' => 'failed',
                'error_message' => $exception->getMessage(),
            ]);

            throw $exception;
        }

        return $request->fresh(['signers']);
    }

    public function applyWebhook(array $payload): ?RdoSignatureRequest
    {
        $providerRequestId = data_get($payload, 'id')
            ?? data_get($payload, 'request_id')
            ?? data_get($payload, 'document_id')
            ?? data_get($payload, 'objectId')
            ?? data_get($payload, 'metadata.rdo_signature_request_id');

        if (! $providerRequestId) {
            return null;
        }

        $request = RdoSignatureRequest::query()
            ->where('provider_request_id', $providerRequestId)
            ->when(is_numeric($providerRequestId), fn ($query) => $query->orWhere('id', (int) $providerRequestId))
            ->first();

        if (! $request) {
            return null;
        }

        $status = $this->normalizeProviderStatus((string) (data_get($payload, 'status') ?? data_get($payload, 'event') ?? 'sent'));
        $request->update([
            'status' => $status,
            'webhook_payload' => $payload,
            'completed_at' => $status === 'completed' ? now() : $request->completed_at,
        ]);

        $this->syncProviderSigners($request->fresh('signers'), data_get($payload, 'signers', []));

        return $request->fresh(['rdo', 'signers']);
    }

    private function storeUnsignedPdf(Tenant $tenant, RdoDiario $rdo, RdoSignatureRequest $request): string
    {
        $fileName = sprintf('rdo-%s-%s-assinatura-%d.pdf', Str::slug($rdo->code), $rdo->reference_date?->format('Ymd'), $request->id);
        $path = "tenant-{$tenant->id}/rdo/{$rdo->id}/assinaturas/{$fileName}";

        Storage::disk('public')->put($path, $this->pdfRenderer->render($tenant, $rdo)->output());

        return $path;
    }

    private function provider(): SignatureProviderInterface
    {
        return match ((string) config('signatures.driver', 'local')) {
            'opensign' => app(OpenSignSignatureProvider::class),
            'local' => app(LocalSignatureProvider::class),
            default => throw new RuntimeException('Provider de assinatura não suportado.'),
        };
    }

    private function resolveSigners(Tenant $tenant, RdoDiario $rdo): Collection
    {
        $signatureResponsibles = RdoResponsavel::query()
            ->with([
                'user:id,name,email',
                'user.tenantMemberships' => fn ($query) => $query
                    ->where('tenant_id', $tenant->id)
                    ->where('status', 'active'),
            ])
            ->where('tenant_id', $tenant->id)
            ->where('contract_id', $rdo->contract_id)
            ->where('etapa', 'assinatura')
            ->where('status', 'active')
            ->get()
            ->pluck('user')
            ->filter();

        if ($signatureResponsibles->isNotEmpty()) {
            return $signatureResponsibles
                ->unique('email')
                ->values()
                ->map(function (User $user) {
                    $membership = $user->tenantMemberships->first();

                    return [
                        'role' => 'assinatura',
                        'user_id' => $user->id,
                        'empresa_id' => $membership?->empresa_id,
                        'name' => $user->name,
                        'email' => Str::lower($user->email),
                    ];
                });
        }

        $roles = [
            'construtora' => $rdo->contract?->construtora_empresa_id,
            'gerenciadora' => $rdo->contract?->fiscalizadora_empresa_id,
            'cliente' => $rdo->contract?->cliente_empresa_id,
        ];

        return collect($roles)
            ->flatMap(function (?int $empresaId, string $role) use ($tenant, $rdo): Collection {
                $users = RdoResponsavel::query()
                    ->with('user:id,name,email')
                    ->where('tenant_id', $tenant->id)
                    ->where('contract_id', $rdo->contract_id)
                    ->where('etapa', $role)
                    ->where('status', 'active')
                    ->get()
                    ->pluck('user')
                    ->filter();

                if ($users->isEmpty() && $empresaId) {
                    $users = TenantUser::query()
                        ->with('user:id,name,email')
                        ->where('tenant_id', $tenant->id)
                        ->where('empresa_id', $empresaId)
                        ->where('status', 'active')
                        ->get()
                        ->pluck('user')
                        ->filter();
                }

                return $users
                    ->unique('email')
                    ->map(fn (User $user) => [
                        'role' => $role,
                        'user_id' => $user->id,
                        'empresa_id' => $empresaId,
                        'name' => $user->name,
                        'email' => Str::lower($user->email),
                    ]);
            })
            ->unique(fn (array $signer) => $signer['role'].'|'.$signer['email'])
            ->values();
    }

    private function notifySigners(RdoSignatureRequest $request): void
    {
        $request->signers
            ->filter(fn (RdoSignatureSigner $signer) => $signer->user !== null)
            ->unique('user_id')
            ->each(fn (RdoSignatureSigner $signer) => $signer->user->notify(
                new RdoSignatureRequestedNotification($request, $request->tenant, $signer->signing_url ?: $request->signing_url)
            ));
    }

    private function syncProviderSigners(RdoSignatureRequest $request, array $providerSigners): void
    {
        collect($providerSigners)->each(function (array $providerSigner) use ($request): void {
            $email = Str::lower((string) ($providerSigner['email'] ?? ''));
            if ($email === '') {
                return;
            }

            $request->signers
                ->first(fn (RdoSignatureSigner $signer) => Str::lower($signer->email) === $email)
                ?->update([
                    'provider_signer_id' => $providerSigner['provider_signer_id'] ?? null,
                    'status' => $this->normalizeProviderStatus((string) ($providerSigner['status'] ?? 'sent')),
                    'signing_url' => $providerSigner['signing_url'] ?? null,
                    'provider_payload' => $providerSigner['raw'] ?? $providerSigner,
                    'signed_at' => $this->normalizeProviderStatus((string) ($providerSigner['status'] ?? '')) === 'completed' ? now() : null,
                ]);
        });
    }

    private function normalizeProviderStatus(string $status): string
    {
        $normalized = Str::of($status)->lower()->replace([' ', '-'], '_')->toString();

        return match ($normalized) {
            'completed', 'complete', 'signed', 'document_completed', 'document_signed' => 'completed',
            'declined', 'rejected', 'cancelled', 'canceled' => 'cancelled',
            'failed', 'error' => 'failed',
            'sent', 'pending', 'created', 'viewed', 'opened' => 'sent',
            default => 'sent',
        };
    }
}
