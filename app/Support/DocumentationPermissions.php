<?php

namespace App\Support;

use App\Models\Contract;
use App\Models\ContractParticipant;
use App\Models\GedDocument;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class DocumentationPermissions
{
    public const VIEW = 'view_documents';
    public const UPLOAD = 'upload_documents';
    public const EDIT = 'edit_documents';
    public const DELETE = 'delete_documents';
    public const OCR = 'manage_document_ocr';
    public const MANAGE_PERMISSIONS = 'manage_document_permissions';
    public const SETTINGS = 'manage_document_settings';
    public const EMAIL = 'manage_document_email';
    public const TRASH = 'manage_document_trash';

    public const LABELS = [
        self::VIEW => 'Visualizar documentos',
        self::UPLOAD => 'Enviar documentos',
        self::EDIT => 'Editar documentos e anexos',
        self::DELETE => 'Mover documentos para a lixeira',
        self::OCR => 'Processar OCR',
        self::MANAGE_PERMISSIONS => 'Gerenciar acessos dos documentos',
        self::SETTINGS => 'Gerenciar tipos e etiquetas',
        self::EMAIL => 'Gerenciar e-mail e triagem',
        self::TRASH => 'Restaurar ou excluir definitivamente',
    ];

    public static function all(): array
    {
        return array_keys(self::LABELS);
    }

    public static function labels(): array
    {
        return self::LABELS;
    }

    public static function normalize(array $permissions): array
    {
        $normalized = collect($permissions)
            ->filter(fn ($permission): bool => in_array($permission, self::all(), true))
            ->unique()
            ->values();

        if ($normalized->contains(fn ($permission): bool => $permission !== self::VIEW)) {
            $normalized->prepend(self::VIEW);
        }

        return $normalized->unique()->values()->all();
    }

    public static function can(User $user, Tenant $tenant, string $permission, ?Contract $contract = null): bool
    {
        if (! in_array($permission, self::all(), true)) {
            return false;
        }

        return in_array($permission, self::permissionsFor($user, $tenant, $contract), true);
    }

    public static function canAny(User $user, Tenant $tenant, string $permission): bool
    {
        if ($user->is_platform_admin || $user->tenantRole($tenant) === TenantRoles::OWNER) {
            return true;
        }

        if ($user->tenantRole($tenant) === TenantRoles::ADMIN) {
            return self::can($user, $tenant, $permission);
        }

        return self::contractIdsFor($user, $tenant, $permission)->isNotEmpty();
    }

    public static function permissionsFor(User $user, Tenant $tenant, ?Contract $contract = null): array
    {
        $role = $user->tenantRole($tenant);

        if ($user->is_platform_admin || $role === TenantRoles::OWNER) {
            return self::all();
        }

        if ($contract && $role !== TenantRoles::ADMIN) {
            $participant = ContractParticipant::query()
                ->where('tenant_id', $tenant->id)
                ->where('contract_id', $contract->id)
                ->where('user_id', $user->id)
                ->where('status', 'active')
                ->first(['role', 'documentation_permissions']);

            if (! $participant) {
                return [];
            }

            if ($participant->documentation_permissions !== null) {
                return self::normalize($participant->documentation_permissions);
            }
        }

        $membership = TenantUser::query()
            ->where('tenant_id', $tenant->id)
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->first(['role', 'documentation_permissions']);

        if (! $membership) {
            return [];
        }

        return $membership->documentation_permissions === null
            ? self::defaultForRole($membership->role)
            : self::normalize($membership->documentation_permissions);
    }

    /** @return Collection<int, int>|null */
    public static function contractIdsFor(User $user, Tenant $tenant, string $permission): ?Collection
    {
        if ($user->is_platform_admin || $user->tenantRole($tenant) === TenantRoles::OWNER) {
            return null;
        }

        if ($user->tenantRole($tenant) === TenantRoles::ADMIN && self::can($user, $tenant, $permission)) {
            return null;
        }

        $membership = TenantUser::query()
            ->where('tenant_id', $tenant->id)
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->first(['role', 'documentation_permissions']);

        if (! $membership) {
            return collect();
        }

        return ContractParticipant::query()
            ->where('tenant_id', $tenant->id)
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->get(['contract_id', 'documentation_permissions'])
            ->filter(function (ContractParticipant $participant) use ($membership, $permission): bool {
                $permissions = $participant->documentation_permissions === null
                    ? ($membership->documentation_permissions ?? self::defaultForRole($membership->role))
                    : $participant->documentation_permissions;

                return in_array($permission, self::normalize($permissions ?: []), true);
            })
            ->pluck('contract_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values();
    }

    public static function canReadDocument(User $user, Tenant $tenant, GedDocument $document): bool
    {
        return self::can($user, $tenant, self::VIEW, $document->contract)
            && self::matchesDocumentAcl($user, $tenant, $document, false);
    }

    public static function canEditDocument(User $user, Tenant $tenant, GedDocument $document, string $permission = self::EDIT): bool
    {
        return self::can($user, $tenant, $permission, $document->contract)
            && self::matchesDocumentAcl($user, $tenant, $document, true);
    }

    public static function scopeReadableDocuments(Builder $query, User $user, Tenant $tenant): Builder
    {
        if (self::isPrivileged($user, $tenant)) {
            return $query;
        }

        $companyId = self::companyId($user, $tenant);

        return $query->where(function (Builder $scope) use ($user, $companyId): void {
            $scope
                ->whereNull('metadata->permissions')
                ->orWhere(function (Builder $legacyPublic): void {
                    $legacyPublic
                        ->whereNull('metadata->permissions->view')
                        ->whereNull('metadata->permissions->edit');
                })
                ->orWhere(function (Builder $public): void {
                    $public
                        ->whereJsonLength('metadata->permissions->view->user_ids', 0)
                        ->whereJsonLength('metadata->permissions->view->empresa_ids', 0)
                        ->whereJsonLength('metadata->permissions->edit->user_ids', 0)
                        ->whereJsonLength('metadata->permissions->edit->empresa_ids', 0);
                })
                ->orWhere('metadata->permissions->owner_user_id', $user->id)
                ->orWhereJsonContains('metadata->permissions->view->user_ids', $user->id)
                ->orWhereJsonContains('metadata->permissions->edit->user_ids', $user->id);

            if ($companyId !== null) {
                $scope
                    ->orWhereJsonContains('metadata->permissions->view->empresa_ids', $companyId)
                    ->orWhereJsonContains('metadata->permissions->edit->empresa_ids', $companyId);
            }
        });
    }

    public static function defaultForRole(?string $role): array
    {
        if (TenantRoles::isTenantAdmin($role)
            || in_array($role, TenantRoles::managementRoles(), true)
            || $role === 'controlador_documentos') {
            return self::all();
        }

        if (in_array($role, TenantRoles::coordinationRoles(), true)) {
            return [self::VIEW, self::UPLOAD, self::EDIT, self::DELETE, self::OCR, self::EMAIL];
        }

        if (in_array($role, [
            ...TenantRoles::engineeringRoles(),
            ...TenantRoles::supervisionRoles(),
            ...TenantRoles::technicalRoles(),
            'engenheiro',
            'administrativo_obra',
            'assistente_administrativo',
            'analista_contratos',
        ], true)) {
            return [self::VIEW, self::UPLOAD, self::EDIT, self::OCR];
        }

        return in_array($role, TenantRoles::administrativeRoles(), true) ? [self::VIEW] : [];
    }

    private static function matchesDocumentAcl(User $user, Tenant $tenant, GedDocument $document, bool $editing): bool
    {
        if ((int) $document->tenant_id !== (int) $tenant->id || self::isPrivileged($user, $tenant)) {
            return (int) $document->tenant_id === (int) $tenant->id;
        }

        $permissions = data_get($document->metadata, 'permissions');

        if (! is_array($permissions)) {
            return true;
        }

        $ownerId = (int) ($permissions['owner_user_id'] ?? $document->uploaded_by_id);
        $viewUserIds = collect(data_get($permissions, 'view.user_ids', []))->map(fn ($id): int => (int) $id);
        $editUserIds = collect(data_get($permissions, 'edit.user_ids', []))->map(fn ($id): int => (int) $id);
        $viewCompanyIds = collect(data_get($permissions, 'view.empresa_ids', []))->map(fn ($id): int => (int) $id);
        $editCompanyIds = collect(data_get($permissions, 'edit.empresa_ids', []))->map(fn ($id): int => (int) $id);
        $hasRestrictions = $viewUserIds->isNotEmpty() || $editUserIds->isNotEmpty()
            || $viewCompanyIds->isNotEmpty() || $editCompanyIds->isNotEmpty();

        if ($ownerId === (int) $user->id || ! $hasRestrictions) {
            return true;
        }

        $companyId = self::companyId($user, $tenant);

        if ($editing) {
            return $editUserIds->contains((int) $user->id)
                || ($companyId !== null && $editCompanyIds->contains($companyId));
        }

        return $viewUserIds->merge($editUserIds)->contains((int) $user->id)
            || ($companyId !== null && $viewCompanyIds->merge($editCompanyIds)->contains($companyId));
    }

    private static function companyId(User $user, Tenant $tenant): ?int
    {
        $companyId = TenantUser::query()
            ->where('tenant_id', $tenant->id)
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->value('empresa_id');

        return $companyId === null ? null : (int) $companyId;
    }

    private static function isPrivileged(User $user, Tenant $tenant): bool
    {
        return $user->is_platform_admin
            || in_array($user->tenantRole($tenant), [TenantRoles::OWNER, TenantRoles::ADMIN], true);
    }
}
