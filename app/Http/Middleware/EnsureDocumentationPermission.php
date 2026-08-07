<?php

namespace App\Http\Middleware;

use App\Models\Contract;
use App\Models\GedCorrespondent;
use App\Models\GedDocument;
use App\Models\GedDocumentType;
use App\Models\GedEmailAccount;
use App\Models\GedEmailProcessedMessage;
use App\Models\GedEmailRule;
use App\Models\GedTag;
use App\Models\Tenant;
use App\Support\DocumentationPermissions;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureDocumentationPermission
{
    public function handle(Request $request, Closure $next, ?string $permission = null): Response
    {
        $tenant = $request->attributes->get('tenant');
        $permission ??= DocumentationPermissions::VIEW;

        abort_unless($tenant instanceof Tenant, 404);
        abort_unless($request->user(), 403);

        $document = $request->route('document');

        if ($document instanceof GedDocument) {
            $allowed = $permission === DocumentationPermissions::VIEW
                ? DocumentationPermissions::canReadDocument($request->user(), $tenant, $document)
                : DocumentationPermissions::canEditDocument($request->user(), $tenant, $document, $permission);

            abort_unless($allowed, 403);

            return $next($request);
        }

        $contract = $this->routeContract($request, $tenant);

        abort_unless(
            $contract
                ? DocumentationPermissions::can($request->user(), $tenant, $permission, $contract)
                : DocumentationPermissions::canAny($request->user(), $tenant, $permission),
            403
        );

        return $next($request);
    }

    private function routeContract(Request $request, Tenant $tenant): ?Contract
    {
        $model = collect(['account', 'rule', 'message', 'type', 'tag', 'correspondent'])
            ->map(fn (string $key) => $request->route($key))
            ->first(fn ($value) => is_object($value));

        $contractId = match (true) {
            $model instanceof GedEmailAccount,
            $model instanceof GedEmailRule,
            $model instanceof GedDocumentType,
            $model instanceof GedTag,
            $model instanceof GedCorrespondent => $model->contract_id,
            $model instanceof GedEmailProcessedMessage => $model->rule?->contract_id,
            default => null,
        };

        if (! $contractId) {
            return null;
        }

        return Contract::query()
            ->where('tenant_id', $tenant->id)
            ->find($contractId);
    }
}
