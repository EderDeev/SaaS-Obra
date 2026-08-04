<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\Contract;
use App\Models\Tenant;
use App\Models\User;
use App\Support\ActivityPermissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TenantActivityAuthorizationMatrixTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    public function test_each_general_permission_only_unlocks_its_expected_capability(): void
    {
        [$tenant, $contract, $owner] = $this->context();
        $activity = $this->activity($tenant, $contract, $owner, 'Atividade da matriz');

        $viewer = $this->member($tenant, $contract, [ActivityPermissions::VIEW]);
        $creator = $this->member($tenant, $contract, [ActivityPermissions::VIEW, ActivityPermissions::CREATE]);
        $editor = $this->member($tenant, $contract, [ActivityPermissions::VIEW, ActivityPermissions::EDIT]);
        $metrics = $this->member($tenant, $contract, [ActivityPermissions::VIEW, ActivityPermissions::VIEW_METRICS]);
        $deleter = $this->member($tenant, $contract, [ActivityPermissions::VIEW, ActivityPermissions::DELETE]);

        $this->actingAs($viewer)
            ->get(route('tenant.activities.index', $tenant))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('canCreateActivities', false)
                ->where('canViewActivityMetrics', false)
                ->where('activities.0.can_edit', false)
                ->where('activities.0.can_move', false)
                ->where('activities.0.can_interact', false)
                ->where('activities.0.can_delete', false));
        $this->actingAs($viewer)
            ->post(route('tenant.activities.store', $tenant), $this->storePayload($contract, 'Bloqueada para visualizador'))
            ->assertForbidden();
        $this->actingAs($viewer)
            ->patch(route('tenant.activities.update', [$tenant, $activity]), ['status' => 'in_progress'])
            ->assertForbidden();
        $this->actingAs($viewer)
            ->delete(route('tenant.activities.destroy', [$tenant, $activity]))
            ->assertForbidden();
        $this->actingAs($viewer)
            ->get(route('tenant.activities.metrics', $tenant))
            ->assertForbidden();

        $this->actingAs($creator)
            ->post(route('tenant.activities.store', $tenant), $this->storePayload($contract, 'Criada pela permissão correta'))
            ->assertRedirect();
        $this->actingAs($creator)
            ->patch(route('tenant.activities.update', [$tenant, $activity]), ['status' => 'in_progress'])
            ->assertForbidden();
        $this->actingAs($creator)
            ->delete(route('tenant.activities.destroy', [$tenant, $activity]))
            ->assertForbidden();

        $this->actingAs($editor)
            ->patch(route('tenant.activities.update', [$tenant, $activity]), $this->updatePayload('Editada pela permissão correta'))
            ->assertRedirect();
        $this->actingAs($editor)
            ->delete(route('tenant.activities.destroy', [$tenant, $activity]))
            ->assertForbidden();
        $this->actingAs($editor)
            ->post(route('tenant.activities.store', $tenant), $this->storePayload($contract, 'Bloqueada para editor'))
            ->assertForbidden();

        $this->actingAs($metrics)
            ->get(route('tenant.activities.metrics', $tenant))
            ->assertOk();
        $this->actingAs($metrics)
            ->patch(route('tenant.activities.update', [$tenant, $activity]), ['status' => 'done'])
            ->assertForbidden();

        $this->actingAs($deleter)
            ->patch(route('tenant.activities.update', [$tenant, $activity]), ['status' => 'done'])
            ->assertForbidden();
        $this->actingAs($deleter)
            ->delete(route('tenant.activities.destroy', [$tenant, $activity]))
            ->assertRedirect();

        $this->assertSoftDeleted('activities', ['id' => $activity->id]);
    }

    public function test_creator_can_fully_manage_own_activity_with_only_view_permission(): void
    {
        [$tenant, $contract] = $this->context();
        $creator = $this->member($tenant, $contract, [ActivityPermissions::VIEW]);
        $activity = $this->activity($tenant, $contract, $creator, 'Atividade do próprio criador');

        $this->actingAs($creator)
            ->get(route('tenant.activities.index', $tenant))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('activities.0.can_edit', true)
                ->where('activities.0.can_move', true)
                ->where('activities.0.can_delete', true));

        $this->actingAs($creator)
            ->patch(route('tenant.activities.update', [$tenant, $activity]), $this->updatePayload('Título alterado pelo criador'))
            ->assertRedirect();
        $this->actingAs($creator)
            ->patch(route('tenant.activities.update', [$tenant, $activity]), ['status' => 'done'])
            ->assertRedirect();
        $this->actingAs($creator)
            ->delete(route('tenant.activities.destroy', [$tenant, $activity]))
            ->assertRedirect();

        $this->assertSoftDeleted('activities', [
            'id' => $activity->id,
            'title' => 'Título alterado pelo criador',
            'status' => 'done',
        ]);
    }

    public function test_assignee_can_move_comment_and_attach_but_cannot_edit_details_or_delete(): void
    {
        Storage::fake('public');

        [$tenant, $contract, $owner] = $this->context();
        $assignee = $this->member($tenant, $contract, [ActivityPermissions::VIEW]);
        $activity = $this->activity(
            $tenant,
            $contract,
            $owner,
            'Atividade atribuída',
            Activity::VISIBILITY_RESTRICTED,
        );
        $activity->update(['assigned_to_id' => $assignee->id]);
        $activity->assignees()->sync([$assignee->id]);

        $this->actingAs($assignee)
            ->get(route('tenant.activities.index', $tenant))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('activities.0.can_edit', false)
                ->where('activities.0.can_move', true)
                ->where('activities.0.can_interact', true)
                ->where('activities.0.can_delete', false));

        $this->actingAs($assignee)
            ->patch(route('tenant.activities.update', [$tenant, $activity]), ['status' => 'in_progress'])
            ->assertRedirect();
        $this->actingAs($assignee)
            ->post(route('tenant.activities.comments.store', [$tenant, $activity]), ['body' => 'Acompanhamento registrado.'])
            ->assertRedirect();
        $this->actingAs($assignee)
            ->post(route('tenant.activities.files.store', [$tenant, $activity]), [
                'file' => UploadedFile::fake()->create('evidencia.pdf', 80, 'application/pdf'),
            ])
            ->assertRedirect();
        $this->actingAs($assignee)
            ->patch(route('tenant.activities.update', [$tenant, $activity]), $this->updatePayload('Tentativa indevida'))
            ->assertForbidden();
        $this->actingAs($assignee)
            ->delete(route('tenant.activities.destroy', [$tenant, $activity]))
            ->assertForbidden();

        $this->assertDatabaseHas('activities', [
            'id' => $activity->id,
            'title' => 'Atividade atribuída',
            'status' => 'in_progress',
        ]);
        $this->assertDatabaseHas('activity_comments', [
            'activity_id' => $activity->id,
            'user_id' => $assignee->id,
        ]);
        $this->assertDatabaseHas('activity_files', [
            'activity_id' => $activity->id,
            'user_id' => $assignee->id,
        ]);
    }

    public function test_permissions_are_isolated_by_contract(): void
    {
        [$tenant, $contractA, $owner] = $this->context();
        $contractB = $tenant->contracts()->create([
            'code' => 'ATV-002',
            'name' => 'Contrato B',
            'status' => 'active',
        ]);
        $user = $this->member($tenant, $contractA, [
            ActivityPermissions::VIEW,
            ActivityPermissions::EDIT,
            ActivityPermissions::DELETE,
        ]);
        $this->linkMember($tenant, $contractB, $user, [ActivityPermissions::VIEW]);

        $activityA = $this->activity($tenant, $contractA, $owner, 'Atividade contrato A');
        $activityB = $this->activity($tenant, $contractB, $owner, 'Atividade contrato B');

        $this->actingAs($user)
            ->get(route('tenant.activities.index', $tenant))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('activities', 2)
                ->where('activities.0.can_edit', true)
                ->where('activities.0.can_delete', true)
                ->where('activities.1.can_edit', false)
                ->where('activities.1.can_delete', false));

        $this->actingAs($user)
            ->patch(route('tenant.activities.update', [$tenant, $activityA]), ['status' => 'in_progress'])
            ->assertRedirect();
        $this->actingAs($user)
            ->delete(route('tenant.activities.destroy', [$tenant, $activityA]))
            ->assertRedirect();
        $this->actingAs($user)
            ->patch(route('tenant.activities.update', [$tenant, $activityB]), ['status' => 'in_progress'])
            ->assertForbidden();
        $this->actingAs($user)
            ->delete(route('tenant.activities.destroy', [$tenant, $activityB]))
            ->assertForbidden();

        $this->assertDatabaseHas('activities', [
            'id' => $activityB->id,
            'status' => 'todo',
            'deleted_at' => null,
        ]);
    }

    public function test_user_without_view_permission_cannot_use_direct_action_routes(): void
    {
        Storage::fake('public');

        [$tenant, $contract] = $this->context();
        $user = $this->member($tenant, $contract, [
            ActivityPermissions::CREATE,
            ActivityPermissions::EDIT,
            ActivityPermissions::VIEW_METRICS,
            ActivityPermissions::DELETE,
        ]);
        $activity = $this->activity($tenant, $contract, $user, 'Atividade sem permissão de leitura');

        $this->actingAs($user)
            ->get(route('tenant.activities.index', $tenant))
            ->assertForbidden();
        $this->actingAs($user)
            ->get(route('tenant.activities.metrics', $tenant))
            ->assertForbidden();
        $this->actingAs($user)
            ->post(route('tenant.activities.store', $tenant), $this->storePayload($contract, 'Tentativa direta'))
            ->assertForbidden();
        $this->actingAs($user)
            ->patch(route('tenant.activities.update', [$tenant, $activity]), ['status' => 'in_progress'])
            ->assertForbidden();
        $this->actingAs($user)
            ->patch(route('tenant.activities.update', [$tenant, $activity]), $this->updatePayload('Tentativa direta'))
            ->assertForbidden();
        $this->actingAs($user)
            ->delete(route('tenant.activities.destroy', [$tenant, $activity]))
            ->assertForbidden();
        $this->actingAs($user)
            ->post(route('tenant.activities.comments.store', [$tenant, $activity]), ['body' => 'Tentativa direta'])
            ->assertForbidden();
        $this->actingAs($user)
            ->post(route('tenant.activities.files.store', [$tenant, $activity]), [
                'file' => UploadedFile::fake()->create('tentativa.pdf', 20, 'application/pdf'),
            ])
            ->assertForbidden();
    }

    public function test_public_viewer_cannot_comment_or_attach_without_being_linked(): void
    {
        Storage::fake('public');

        [$tenant, $contract, $owner] = $this->context();
        $viewer = $this->member($tenant, $contract, [ActivityPermissions::VIEW]);
        $editor = $this->member($tenant, $contract, [ActivityPermissions::VIEW, ActivityPermissions::EDIT]);
        $activity = $this->activity($tenant, $contract, $owner, 'Atividade pública para interação');

        $this->actingAs($viewer)
            ->post(route('tenant.activities.comments.store', [$tenant, $activity]), ['body' => 'Comentário indevido'])
            ->assertForbidden();
        $this->actingAs($viewer)
            ->post(route('tenant.activities.files.store', [$tenant, $activity]), [
                'file' => UploadedFile::fake()->create('indevido.pdf', 20, 'application/pdf'),
            ])
            ->assertForbidden();

        $this->actingAs($editor)
            ->post(route('tenant.activities.comments.store', [$tenant, $activity]), ['body' => 'Comentário autorizado'])
            ->assertRedirect();
        $this->actingAs($editor)
            ->post(route('tenant.activities.files.store', [$tenant, $activity]), [
                'file' => UploadedFile::fake()->create('autorizado.pdf', 20, 'application/pdf'),
            ])
            ->assertRedirect();

        $this->assertDatabaseMissing('activity_comments', ['body' => 'Comentário indevido']);
        $this->assertDatabaseHas('activity_comments', ['body' => 'Comentário autorizado']);
        $this->assertDatabaseMissing('activity_files', ['name' => 'indevido.pdf']);
        $this->assertDatabaseHas('activity_files', ['name' => 'autorizado.pdf']);
    }

    public function test_restricted_activity_remains_private_even_with_general_edit_and_delete(): void
    {
        [$tenant, $contract, $owner] = $this->context();
        $manager = $this->member($tenant, $contract, ActivityPermissions::all());
        $activity = $this->activity(
            $tenant,
            $contract,
            $owner,
            'Atividade restrita privada',
            Activity::VISIBILITY_RESTRICTED,
        );

        $this->actingAs($manager)
            ->get(route('tenant.activities.index', $tenant))
            ->assertOk()
            ->assertDontSee('Atividade restrita privada');
        $this->actingAs($manager)
            ->patch(route('tenant.activities.update', [$tenant, $activity]), ['status' => 'in_progress'])
            ->assertForbidden();
        $this->actingAs($manager)
            ->delete(route('tenant.activities.destroy', [$tenant, $activity]))
            ->assertForbidden();
    }

    public function test_activity_from_another_tenant_cannot_be_changed_through_current_tenant_route(): void
    {
        [$tenantA, , $ownerA] = $this->context('tenant-a');
        [$tenantB, $contractB, $ownerB] = $this->context('tenant-b');
        $activityB = $this->activity($tenantB, $contractB, $ownerB, 'Atividade de outro tenant');

        $this->actingAs($ownerA)
            ->patch(route('tenant.activities.update', [$tenantA, $activityB]), ['status' => 'done'])
            ->assertNotFound();
        $this->actingAs($ownerA)
            ->delete(route('tenant.activities.destroy', [$tenantA, $activityB]))
            ->assertNotFound();

        $this->assertDatabaseHas('activities', [
            'id' => $activityB->id,
            'status' => 'todo',
            'deleted_at' => null,
        ]);
    }

    public function test_inactive_contract_participant_cannot_view_or_change_activities(): void
    {
        [$tenant, $contract, $owner] = $this->context();
        $user = $this->member($tenant, $contract, ActivityPermissions::all());
        $contract->participants()->where('user_id', $user->id)->update(['status' => 'inactive']);
        $activity = $this->activity($tenant, $contract, $owner, 'Atividade para participante inativo');

        $this->actingAs($user)
            ->get(route('tenant.activities.index', $tenant))
            ->assertForbidden();
        $this->actingAs($user)
            ->patch(route('tenant.activities.update', [$tenant, $activity]), ['status' => 'in_progress'])
            ->assertForbidden();
        $this->actingAs($user)
            ->delete(route('tenant.activities.destroy', [$tenant, $activity]))
            ->assertForbidden();
    }

    /**
     * @return array{Tenant, Contract, User}
     */
    private function context(string $slug = 'atividades-teste'): array
    {
        $tenant = Tenant::create([
            'slug' => $slug,
            'name' => "Tenant {$slug}",
            'plan' => 'starter',
            'status' => 'active',
        ]);
        $owner = User::factory()->create();
        $tenant->memberships()->create([
            'user_id' => $owner->id,
            'role' => 'tenant_owner',
            'status' => 'active',
        ]);
        $contract = $tenant->contracts()->create([
            'code' => 'ATV-001',
            'name' => 'Contrato A',
            'status' => 'active',
        ]);

        return [$tenant, $contract, $owner];
    }

    private function member(Tenant $tenant, Contract $contract, array $permissions): User
    {
        $user = User::factory()->create();
        $tenant->memberships()->create([
            'user_id' => $user->id,
            'role' => 'engineer',
            'status' => 'active',
            'activity_permissions' => $permissions,
        ]);
        $this->linkMember($tenant, $contract, $user, $permissions);

        return $user;
    }

    private function linkMember(Tenant $tenant, Contract $contract, User $user, array $permissions): void
    {
        $contract->participants()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'side' => 'manager',
            'role' => 'team_member',
            'status' => 'active',
            'activity_permissions' => $permissions,
        ]);
    }

    private function activity(
        Tenant $tenant,
        Contract $contract,
        User $creator,
        string $title,
        string $visibility = Activity::VISIBILITY_PUBLIC,
    ): Activity {
        return $tenant->activities()->create([
            'contract_id' => $contract->id,
            'created_by_id' => $creator->id,
            'title' => $title,
            'visibility' => $visibility,
            'status' => 'todo',
            'priority' => 'normal',
        ]);
    }

    private function storePayload(Contract $contract, string $title): array
    {
        return [
            'contract_id' => $contract->id,
            'title' => $title,
            'priority' => 'normal',
        ];
    }

    private function updatePayload(string $title): array
    {
        return [
            'title' => $title,
            'description' => 'Descrição atualizada.',
            'category' => 'administrative',
            'visibility' => Activity::VISIBILITY_PUBLIC,
            'priority' => 'high',
            'assigned_to_ids' => [],
        ];
    }
}
