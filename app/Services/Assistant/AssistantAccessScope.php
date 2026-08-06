<?php

namespace App\Services\Assistant;

use App\Models\Contract;
use App\Models\ContractParticipant;
use App\Models\GedDocument;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Support\ActivityPermissions;
use App\Support\DocumentationPermissions;
use App\Support\ProjectPermissions;
use App\Support\RncPermissions;
use App\Support\TenantRoles;
use Illuminate\Support\Collection;

class AssistantAccessScope
{
    /** @return Collection<int, int> */
    public function tenantIds(User $user): Collection
    {
        if ($user->is_platform_admin) {
            return Tenant::query()
                ->pluck('id')
                ->map(fn ($id): int => (int) $id);
        }

        $membershipIds = TenantUser::query()
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->pluck('tenant_id');
        $participantIds = ContractParticipant::query()
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->pluck('tenant_id');

        return $membershipIds
            ->merge($participantIds)
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values();
    }

    /** @return Collection<int, int> */
    public function contractIds(User $user, Tenant $tenant): Collection
    {
        if ($this->isTenantAdministrator($user, $tenant)) {
            return Contract::query()
                ->where('tenant_id', $tenant->id)
                ->pluck('id')
                ->map(fn ($id): int => (int) $id);
        }

        return ContractParticipant::query()
            ->where('tenant_id', $tenant->id)
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->pluck('contract_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values();
    }

    /** @return Collection<int, int> */
    public function activityContractIds(User $user, Tenant $tenant): Collection
    {
        return $this->moduleContractIds(
            $this->contractIds($user, $tenant),
            ActivityPermissions::contractIdsFor($user, $tenant, ActivityPermissions::VIEW)
        );
    }

    /** @return Collection<int, int> */
    public function projectContractIds(User $user, Tenant $tenant): Collection
    {
        return $this->moduleContractIds(
            $this->contractIds($user, $tenant),
            ProjectPermissions::contractIdsFor($user, $tenant, ProjectPermissions::VIEW)
        );
    }

    /** @return Collection<int, int> */
    public function rncContractIds(User $user, Tenant $tenant): Collection
    {
        return $this->moduleContractIds(
            $this->contractIds($user, $tenant),
            RncPermissions::contractIdsFor($user, $tenant, RncPermissions::VIEW)
        );
    }

    /** @return Collection<int, int> */
    public function documentationContractIds(User $user, Tenant $tenant): Collection
    {
        return $this->moduleContractIds(
            $this->contractIds($user, $tenant),
            DocumentationPermissions::contractIdsFor($user, $tenant, DocumentationPermissions::VIEW)
        );
    }

    public function canReadDocument(User $user, Tenant $tenant, GedDocument $document): bool
    {
        return DocumentationPermissions::canReadDocument($user, $tenant, $document);
    }

    /** @param Collection<int, int> $contractIds */
    public function canReadDocumentWithinScope(
        User $user,
        Tenant $tenant,
        GedDocument $document,
        Collection $contractIds,
        ?int $companyId
    ): bool {
        return $contractIds->contains((int) $document->contract_id)
            && DocumentationPermissions::canReadDocument($user, $tenant, $document);
    }

    public function companyId(User $user, Tenant $tenant): ?int
    {
        $companyId = TenantUser::query()
            ->where('tenant_id', $tenant->id)
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->value('empresa_id');

        return $companyId !== null ? (int) $companyId : null;
    }

    public function isTenantAdministrator(User $user, Tenant $tenant): bool
    {
        return $user->is_platform_admin
            || in_array($user->tenantRole($tenant), [TenantRoles::OWNER, TenantRoles::ADMIN], true);
    }

    /**
     * @param  Collection<int, int>  $baseIds
     * @param  Collection<int, int>|null  $moduleIds
     * @return Collection<int, int>
     */
    private function moduleContractIds(Collection $baseIds, ?Collection $moduleIds): Collection
    {
        if ($moduleIds === null) {
            return $baseIds;
        }

        return $baseIds
            ->intersect($moduleIds->map(fn ($id): int => (int) $id))
            ->values();
    }
}
