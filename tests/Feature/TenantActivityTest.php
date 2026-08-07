<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\Contract;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\ActivityAssignedNotification;
use App\Notifications\ActivityCommentedNotification;
use App\Notifications\ActivityFileUploadedNotification;
use App\Notifications\ActivityStatusChangedNotification;
use App\Support\ActivityPermissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TenantActivityTest extends TestCase
{
    use RefreshDatabase;

    public function test_tenant_admin_can_create_activity_with_multiple_assignees_and_notify_them(): void
    {
        [$tenant, $admin, $contract] = $this->tenantWithUser('tenant_admin');
        $engineer = User::factory()->create();
        $manager = User::factory()->create();

        foreach ([$engineer, $manager] as $user) {
            $tenant->memberships()->create([
                'user_id' => $user->id,
                'role' => 'engineer',
                'status' => 'active',
            ]);

            $contract->participants()->create([
                'tenant_id' => $tenant->id,
                'user_id' => $user->id,
                'side' => 'manager',
                'role' => 'team_member',
                'status' => 'active',
            ]);
        }

        $this->actingAs($admin)
            ->post(route('tenant.activities.store', $tenant), [
                'contract_id' => $contract->id,
                'assigned_to_ids' => [$engineer->id, $manager->id],
                'activity_type' => Activity::TYPE_ACTIVITY,
                'checklist_items' => [''],
                'title' => 'Validar RDO',
                'category' => 'construction_diary',
                'visibility' => 'restricted',
                'description' => 'Conferir anexos do diário de obra.',
                'priority' => 'high',
                'due_date' => '2026-06-10',
            ])
            ->assertRedirect();

        $activity = Activity::where('title', 'Validar RDO')->firstOrFail();

        $this->assertDatabaseHas('activities', [
            'tenant_id' => $tenant->id,
            'contract_id' => $contract->id,
            'assigned_to_id' => $engineer->id,
            'created_by_id' => $admin->id,
            'status' => 'todo',
            'category' => 'construction_diary',
            'visibility' => 'restricted',
            'priority' => 'high',
        ]);
        $this->assertDatabaseHas('activity_user', [
            'activity_id' => $activity->id,
            'user_id' => $engineer->id,
        ]);
        $this->assertDatabaseHas('activity_user', [
            'activity_id' => $activity->id,
            'user_id' => $manager->id,
        ]);

        $engineerNotification = $engineer->notifications()->first();
        $managerNotification = $manager->notifications()->first();

        $this->assertNotNull($engineerNotification);
        $this->assertNotNull($managerNotification);
        $this->assertSame($activity->id, $engineerNotification->data['activity_id']);
        $this->assertSame($activity->id, $managerNotification->data['activity_id']);
        $this->assertSame('Nova atividade atribuída', $engineerNotification->data['title']);
    }

    public function test_activity_creation_notifies_assignees_by_database_and_mail(): void
    {
        Notification::fake();

        [$tenant, $admin, $contract] = $this->tenantWithUser('tenant_admin');
        $engineer = User::factory()->create();

        $tenant->memberships()->create([
            'user_id' => $engineer->id,
            'role' => 'engineer',
            'status' => 'active',
        ]);

        $contract->participants()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $engineer->id,
            'side' => 'manager',
            'role' => 'team_member',
            'status' => 'active',
        ]);

        $this->actingAs($admin)
            ->post(route('tenant.activities.store', $tenant), [
                'contract_id' => $contract->id,
                'assigned_to_ids' => [$engineer->id],
                'title' => 'Validar RDO',
                'description' => 'Conferir anexos do diario de obra.',
                'priority' => 'high',
                'due_date' => '2026-06-10',
            ])
            ->assertRedirect();

        Notification::assertSentTo($engineer, ActivityAssignedNotification::class, function ($notification, array $channels): bool {
            return in_array('database', $channels, true) && in_array('mail', $channels, true);
        });
    }

    public function test_user_without_view_activity_permission_cannot_access_activities(): void
    {
        [$tenant, $user, $contract] = $this->tenantWithUser('engineer');

        $tenant->memberships()
            ->where('user_id', $user->id)
            ->update(['activity_permissions' => []]);

        $contract->participants()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'side' => 'manager',
            'role' => 'team_member',
            'status' => 'active',
        ]);

        $this->actingAs($user)
            ->get(route('tenant.activities.index', $tenant))
            ->assertForbidden();

        $this->actingAs($user)
            ->get(route('tenant.activities.metrics', $tenant))
            ->assertForbidden();

        $this->actingAs($user)
            ->get(route('tenant.activities.tour-preview', $tenant))
            ->assertForbidden();
    }

    public function test_activity_metrics_require_specific_permission(): void
    {
        [$tenant, $user, $contract] = $this->tenantWithUser('engineer');

        $tenant->memberships()
            ->where('user_id', $user->id)
            ->update(['activity_permissions' => [ActivityPermissions::VIEW]]);

        $participant = $contract->participants()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'side' => 'manager',
            'role' => 'team_member',
            'status' => 'active',
            'activity_permissions' => [ActivityPermissions::VIEW],
        ]);

        $this->actingAs($user)
            ->get(route('tenant.activities.index', $tenant))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('canViewActivityMetrics', false)
            );

        $this->actingAs($user)
            ->get(route('tenant.activities.metrics', $tenant))
            ->assertForbidden();

        $permissions = [ActivityPermissions::VIEW, ActivityPermissions::VIEW_METRICS];

        $tenant->memberships()
            ->where('user_id', $user->id)
            ->update(['activity_permissions' => $permissions]);
        $participant->update(['activity_permissions' => $permissions]);

        $this->actingAs($user)
            ->get(route('tenant.activities.index', $tenant))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('canViewActivityMetrics', true)
            );

        $this->actingAs($user)
            ->get(route('tenant.activities.metrics', $tenant))
            ->assertOk();
    }

    public function test_user_without_create_activity_permission_cannot_create_activity(): void
    {
        [$tenant, $user, $contract] = $this->tenantWithUser('engineer');

        $tenant->memberships()
            ->where('user_id', $user->id)
            ->update(['activity_permissions' => [ActivityPermissions::VIEW]]);

        $contract->participants()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'side' => 'manager',
            'role' => 'team_member',
            'status' => 'active',
        ]);

        $this->actingAs($user)
            ->post(route('tenant.activities.store', $tenant), [
                'contract_id' => $contract->id,
                'title' => 'Atividade bloqueada',
                'priority' => 'normal',
            ])
            ->assertForbidden();
    }

    public function test_creator_can_edit_and_delete_own_activity_without_general_permissions(): void
    {
        [$tenant, $user, $contract] = $this->tenantWithUser('engineer');

        $tenant->memberships()
            ->where('user_id', $user->id)
            ->update(['activity_permissions' => [ActivityPermissions::VIEW, ActivityPermissions::CREATE]]);

        $contract->participants()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'side' => 'manager',
            'role' => 'team_member',
            'status' => 'active',
        ]);

        $activity = $tenant->activities()->create([
            'contract_id' => $contract->id,
            'created_by_id' => $user->id,
            'title' => 'Atividade protegida',
            'status' => 'todo',
            'priority' => 'normal',
        ]);

        $this->actingAs($user)
            ->get(route('tenant.activities.index', $tenant))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('activities.0.can_edit', true)
                ->where('activities.0.can_delete', true)
            );

        $this->actingAs($user)
            ->patch(route('tenant.activities.update', [$tenant, $activity]), [
                'status' => 'done',
            ])
            ->assertRedirect();

        $this->actingAs($user)
            ->delete(route('tenant.activities.destroy', [$tenant, $activity]))
            ->assertRedirect();

        $this->assertSoftDeleted('activities', [
            'id' => $activity->id,
            'status' => 'done',
        ]);
    }

    public function test_user_without_edit_or_delete_permissions_cannot_change_activity_created_by_another_user(): void
    {
        [$tenant, $user, $contract] = $this->tenantWithUser('engineer');
        $creator = User::factory()->create();

        $tenant->memberships()
            ->where('user_id', $user->id)
            ->update(['activity_permissions' => [ActivityPermissions::VIEW, ActivityPermissions::CREATE]]);

        $contract->participants()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'side' => 'manager',
            'role' => 'team_member',
            'status' => 'active',
        ]);

        $activity = $tenant->activities()->create([
            'contract_id' => $contract->id,
            'created_by_id' => $creator->id,
            'title' => 'Atividade de outro usuário',
            'visibility' => Activity::VISIBILITY_PUBLIC,
            'status' => 'todo',
            'priority' => 'normal',
        ]);

        $this->actingAs($user)
            ->get(route('tenant.activities.index', $tenant))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('activities.0.can_edit', false)
                ->where('activities.0.can_delete', false)
            );

        $this->actingAs($user)
            ->patch(route('tenant.activities.update', [$tenant, $activity]), [
                'status' => 'done',
            ])
            ->assertForbidden();

        $this->actingAs($user)
            ->delete(route('tenant.activities.destroy', [$tenant, $activity]))
            ->assertForbidden();

        $this->assertDatabaseHas('activities', [
            'id' => $activity->id,
            'status' => 'todo',
            'deleted_at' => null,
        ]);
    }

    public function test_operational_user_only_sees_activities_from_linked_contracts(): void
    {
        [$tenant, $engineer, $visibleContract] = $this->tenantWithUser('engineer');
        $hiddenContract = $tenant->contracts()->create([
            'code' => 'CT-002',
            'name' => 'Contrato Oculto',
            'status' => 'active',
        ]);

        $visibleContract->participants()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $engineer->id,
            'side' => 'manager',
            'role' => 'team_member',
            'status' => 'active',
        ]);

        $tenant->activities()->create([
            'contract_id' => $visibleContract->id,
            'created_by_id' => $engineer->id,
            'title' => 'Atividade visivel',
            'status' => 'todo',
            'priority' => 'normal',
        ]);

        $tenant->activities()->create([
            'contract_id' => $hiddenContract->id,
            'created_by_id' => $engineer->id,
            'title' => 'Atividade oculta',
            'status' => 'todo',
            'priority' => 'normal',
        ]);

        $this->actingAs($engineer)
            ->get(route('tenant.activities.index', $tenant))
            ->assertOk()
            ->assertSee('Atividade visivel')
            ->assertDontSee('Atividade oculta');
    }

    public function test_activity_cards_receive_assignee_avatar_urls(): void
    {
        [$tenant, $admin, $contract] = $this->tenantWithUser('tenant_admin');
        $engineer = User::factory()->create([
            'avatar_url' => '/storage/avatars/responsavel.png',
        ]);

        $tenant->memberships()->create([
            'user_id' => $engineer->id,
            'role' => 'engineer',
            'status' => 'active',
        ]);

        $contract->participants()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $engineer->id,
            'side' => 'manager',
            'role' => 'team_member',
            'status' => 'active',
        ]);

        $activity = $tenant->activities()->create([
            'contract_id' => $contract->id,
            'created_by_id' => $admin->id,
            'title' => 'Atividade com foto',
            'status' => 'todo',
            'priority' => 'normal',
        ]);

        $activity->assignees()->sync([$engineer->id]);

        $this->actingAs($admin)
            ->get(route('tenant.activities.index', $tenant))
            ->assertOk()
            ->assertSee('Atividade com foto')
            ->assertSee('\\/storage\\/avatars\\/responsavel.png', false);
    }

    public function test_activity_metrics_show_productivity_and_deadline_performance(): void
    {
        [$tenant, $admin, $contract] = $this->tenantWithUser('tenant_admin');
        $engineer = User::factory()->create();

        $tenant->memberships()->create([
            'user_id' => $engineer->id,
            'role' => 'engineer',
            'status' => 'active',
        ]);
        $contract->participants()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $engineer->id,
            'side' => 'manager',
            'role' => 'team_member',
            'status' => 'active',
        ]);

        $onTime = $tenant->activities()->create([
            'contract_id' => $contract->id,
            'created_by_id' => $admin->id,
            'title' => 'Concluida no prazo',
            'category' => 'project',
            'status' => 'done',
            'priority' => 'normal',
            'due_date' => now()->subDays(2)->toDateString(),
            'completed_at' => now()->subDays(3),
            'created_at' => now()->subDays(8),
        ]);
        $late = $tenant->activities()->create([
            'contract_id' => $contract->id,
            'created_by_id' => $admin->id,
            'title' => 'Concluida atrasada',
            'category' => 'documentation',
            'status' => 'done',
            'priority' => 'high',
            'due_date' => now()->subDays(5)->toDateString(),
            'completed_at' => now()->subDay(),
            'created_at' => now()->subDays(10),
        ]);
        $overdue = $tenant->activities()->create([
            'contract_id' => $contract->id,
            'created_by_id' => $admin->id,
            'title' => 'Aberta atrasada',
            'category' => 'documentation',
            'status' => 'in_progress',
            'priority' => 'urgent',
            'due_date' => now()->subDays(4)->toDateString(),
            'created_at' => now()->subDays(12),
        ]);

        foreach ([$onTime, $late, $overdue] as $activity) {
            $activity->assignees()->sync([$engineer->id]);
        }

        $this->actingAs($admin)
            ->get(route('tenant.activities.metrics', [$tenant, 'period' => 'all']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Tenant/Activities/Metrics')
                ->where('summary.total', 3)
                ->where('summary.completed', 2)
                ->where('summary.open', 1)
                ->where('summary.overdue_open', 1)
                ->where('summary.on_time_rate', 50)
                ->where('responsibles.0.id', $engineer->id)
                ->where('responsibles.0.total', 3)
                ->where('responsibles.0.completed', 2)
                ->where('responsibles.0.on_time', 1)
                ->where('responsibles.0.late', 1)
                ->has('resolvedActivities', 2)
                ->has('overdueActivities', 1)
            );
    }

    public function test_activity_tour_uses_demo_board_and_metrics_without_database_records(): void
    {
        [$tenant, $admin] = $this->tenantWithUser('tenant_admin');

        $this->actingAs($admin)
            ->get(route('tenant.activities.tour-preview', [$tenant, 'screen' => 'detail']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Tenant/Activities/Index')
                ->where('tourMode', true)
                ->where('tourScreen', 'detail')
                ->where('activities.0._tourData', true)
                ->where('activities.0.title', 'Validar medição mensal da obra')
                ->has('activities.0.comments', 2)
                ->has('activities.0.files', 1)
            );

        $this->actingAs($admin)
            ->get(route('tenant.activities.tour-preview', [$tenant, 'screen' => 'metrics']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Tenant/Activities/Metrics')
                ->where('tourSection', 'metrics')
                ->where('summary.total', 28)
                ->where('summary.on_time_rate', 86)
                ->has('charts.trend', 6)
                ->has('responsibles', 2)
            );
    }

    public function test_operational_user_cannot_move_activity_from_unlinked_contract(): void
    {
        [$tenant, $engineer] = $this->tenantWithUser('engineer');
        $contract = $tenant->contracts()->create([
            'code' => 'CT-002',
            'name' => 'Contrato Oculto',
            'status' => 'active',
        ]);
        $activity = $tenant->activities()->create([
            'contract_id' => $contract->id,
            'created_by_id' => $engineer->id,
            'title' => 'Atividade bloqueada',
            'status' => 'todo',
            'priority' => 'normal',
        ]);

        $this->actingAs($engineer)
            ->patch(route('tenant.activities.update', [$tenant, $activity]), [
                'status' => 'done',
            ])
            ->assertForbidden();

        $this->assertDatabaseHas('activities', [
            'id' => $activity->id,
            'status' => 'todo',
        ]);
    }

    public function test_restricted_activity_is_only_visible_to_creator_and_assignees(): void
    {
        [$tenant, $admin, $contract] = $this->tenantWithUser('tenant_admin');
        $assignee = User::factory()->create();
        $contractViewer = User::factory()->create();

        foreach ([$assignee, $contractViewer] as $user) {
            $tenant->memberships()->create([
                'user_id' => $user->id,
                'role' => 'engineer',
                'status' => 'active',
            ]);
            $contract->participants()->create([
                'tenant_id' => $tenant->id,
                'user_id' => $user->id,
                'side' => 'manager',
                'role' => 'team_member',
                'status' => 'active',
            ]);
        }

        $publicActivity = $tenant->activities()->create([
            'contract_id' => $contract->id,
            'created_by_id' => $admin->id,
            'title' => 'Atividade publica',
            'visibility' => Activity::VISIBILITY_PUBLIC,
            'status' => 'todo',
            'priority' => 'normal',
        ]);
        $restrictedActivity = $tenant->activities()->create([
            'contract_id' => $contract->id,
            'created_by_id' => $admin->id,
            'title' => 'Atividade restrita',
            'visibility' => Activity::VISIBILITY_RESTRICTED,
            'status' => 'todo',
            'priority' => 'normal',
        ]);
        $restrictedActivity->assignees()->sync([$assignee->id]);

        $this->actingAs($contractViewer)
            ->get(route('tenant.activities.index', $tenant))
            ->assertOk()
            ->assertSee($publicActivity->title)
            ->assertDontSee($restrictedActivity->title);

        $this->actingAs($assignee)
            ->get(route('tenant.activities.index', $tenant))
            ->assertOk()
            ->assertSee($publicActivity->title)
            ->assertSee($restrictedActivity->title);

        $this->actingAs($admin)
            ->get(route('tenant.activities.index', $tenant))
            ->assertOk()
            ->assertSee($restrictedActivity->title);
    }

    public function test_unassigned_user_cannot_access_restricted_activity_actions(): void
    {
        [$tenant, $creator, $contract] = $this->tenantWithUser('engineer');
        $contract->participants()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $creator->id,
            'side' => 'manager',
            'role' => 'team_member',
            'status' => 'active',
        ]);

        $viewer = User::factory()->create();
        $tenant->memberships()->create([
            'user_id' => $viewer->id,
            'role' => 'engineer',
            'status' => 'active',
        ]);
        $contract->participants()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $viewer->id,
            'side' => 'manager',
            'role' => 'team_member',
            'status' => 'active',
        ]);

        $activity = $tenant->activities()->create([
            'contract_id' => $contract->id,
            'created_by_id' => $creator->id,
            'title' => 'Atividade confidencial',
            'visibility' => Activity::VISIBILITY_RESTRICTED,
            'status' => 'todo',
            'priority' => 'normal',
        ]);

        $this->actingAs($viewer)
            ->post(route('tenant.activities.comments.store', [$tenant, $activity]), [
                'body' => 'Tentativa de acesso.',
            ])
            ->assertForbidden();

        $this->actingAs($viewer)
            ->patch(route('tenant.activities.update', [$tenant, $activity]), [
                'status' => 'in_progress',
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('activity_comments', [
            'activity_id' => $activity->id,
            'user_id' => $viewer->id,
        ]);
    }

    public function test_status_change_notifies_activity_assignees(): void
    {
        [$tenant, $admin, $contract] = $this->tenantWithUser('tenant_admin');
        $engineer = User::factory()->create();

        $tenant->memberships()->create([
            'user_id' => $engineer->id,
            'role' => 'engineer',
            'status' => 'active',
        ]);

        $contract->participants()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $engineer->id,
            'side' => 'manager',
            'role' => 'team_member',
            'status' => 'active',
        ]);

        $activity = $tenant->activities()->create([
            'contract_id' => $contract->id,
            'created_by_id' => $admin->id,
            'title' => 'Atualizar cronograma',
            'status' => 'todo',
            'priority' => 'normal',
        ]);
        $activity->assignees()->sync([$engineer->id]);

        $this->actingAs($admin)
            ->patch(route('tenant.activities.update', [$tenant, $activity]), [
                'status' => 'in_progress',
            ])
            ->assertRedirect();

        $notification = $engineer->notifications()->first();

        $this->assertNotNull($notification);
        $this->assertSame('Status da atividade alterado', $notification->data['title']);
        $this->assertSame($activity->id, $notification->data['activity_id']);
        $this->assertDatabaseHas('activities', [
            'id' => $activity->id,
            'status' => 'in_progress',
        ]);
    }

    public function test_status_change_notifies_activity_assignees_by_database_and_mail(): void
    {
        Notification::fake();

        [$tenant, $admin, $contract] = $this->tenantWithUser('tenant_admin');
        $engineer = User::factory()->create();

        $tenant->memberships()->create([
            'user_id' => $engineer->id,
            'role' => 'engineer',
            'status' => 'active',
        ]);

        $contract->participants()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $engineer->id,
            'side' => 'manager',
            'role' => 'team_member',
            'status' => 'active',
        ]);

        $activity = $tenant->activities()->create([
            'contract_id' => $contract->id,
            'created_by_id' => $admin->id,
            'title' => 'Atualizar cronograma',
            'status' => 'todo',
            'priority' => 'normal',
        ]);
        $activity->assignees()->sync([$engineer->id]);

        $this->actingAs($admin)
            ->patch(route('tenant.activities.update', [$tenant, $activity]), [
                'status' => 'in_progress',
            ])
            ->assertRedirect();

        Notification::assertSentTo($engineer, ActivityStatusChangedNotification::class, function ($notification, array $channels): bool {
            return in_array('database', $channels, true) && in_array('mail', $channels, true);
        });
    }

    public function test_tenant_admin_can_edit_activity_details_and_soft_delete_activity(): void
    {
        [$tenant, $admin, $contract] = $this->tenantWithUser('tenant_admin');
        $engineer = User::factory()->create();

        $tenant->memberships()->create([
            'user_id' => $engineer->id,
            'role' => 'engineer',
            'status' => 'active',
        ]);

        $contract->participants()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $engineer->id,
            'side' => 'manager',
            'role' => 'team_member',
            'status' => 'active',
        ]);

        $activity = $tenant->activities()->create([
            'contract_id' => $contract->id,
            'created_by_id' => $admin->id,
            'title' => 'Titulo antigo',
            'status' => 'todo',
            'priority' => 'normal',
        ]);

        $this->actingAs($admin)
            ->patch(route('tenant.activities.update', [$tenant, $activity]), [
                'title' => 'Titulo editado',
                'description' => 'Descricao editada',
                'category' => 'project',
                'priority' => 'urgent',
                'due_date' => '2026-06-20',
                'assigned_to_ids' => [$engineer->id],
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('activities', [
            'id' => $activity->id,
            'title' => 'Titulo editado',
            'category' => 'project',
            'priority' => 'urgent',
        ]);
        $this->assertDatabaseHas('activity_user', [
            'activity_id' => $activity->id,
            'user_id' => $engineer->id,
        ]);

        $this->actingAs($admin)
            ->delete(route('tenant.activities.destroy', [$tenant, $activity]))
            ->assertRedirect();

        $this->assertSoftDeleted('activities', [
            'id' => $activity->id,
        ]);
    }

    public function test_completed_activities_older_than_five_days_are_hidden_but_not_deleted(): void
    {
        [$tenant, $engineer, $contract] = $this->tenantWithUser('engineer');

        $contract->participants()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $engineer->id,
            'side' => 'manager',
            'role' => 'team_member',
            'status' => 'active',
        ]);

        $tenant->activities()->create([
            'contract_id' => $contract->id,
            'created_by_id' => $engineer->id,
            'title' => 'Concluida recente',
            'status' => 'done',
            'priority' => 'normal',
            'completed_at' => now()->subDays(4),
        ]);
        $hidden = $tenant->activities()->create([
            'contract_id' => $contract->id,
            'created_by_id' => $engineer->id,
            'title' => 'Concluida antiga',
            'status' => 'done',
            'priority' => 'normal',
            'completed_at' => now()->subDays(6),
        ]);

        $this->actingAs($engineer)
            ->get(route('tenant.activities.index', $tenant))
            ->assertOk()
            ->assertSee('Concluida recente')
            ->assertDontSee('Concluida antiga');

        $this->assertDatabaseHas('activities', [
            'id' => $hidden->id,
            'title' => 'Concluida antiga',
            'status' => 'done',
        ]);
    }

    public function test_linked_user_can_comment_and_attach_file_to_activity(): void
    {
        Storage::fake('public');

        [$tenant, $engineer, $contract] = $this->tenantWithUser('engineer');

        $contract->participants()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $engineer->id,
            'side' => 'manager',
            'role' => 'team_member',
            'status' => 'active',
        ]);

        $activity = $tenant->activities()->create([
            'contract_id' => $contract->id,
            'created_by_id' => $engineer->id,
            'title' => 'Conferir projeto',
            'status' => 'todo',
            'priority' => 'normal',
        ]);

        $this->actingAs($engineer)
            ->post(route('tenant.activities.comments.store', [$tenant, $activity]), [
                'body' => 'Arquivo conferido pela equipe.',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('activity_comments', [
            'tenant_id' => $tenant->id,
            'activity_id' => $activity->id,
            'user_id' => $engineer->id,
            'body' => 'Arquivo conferido pela equipe.',
        ]);

        $this->actingAs($engineer)
            ->post(route('tenant.activities.files.store', [$tenant, $activity]), [
                'file' => UploadedFile::fake()->create('projeto.pdf', 120, 'application/pdf'),
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('activity_files', [
            'tenant_id' => $tenant->id,
            'activity_id' => $activity->id,
            'user_id' => $engineer->id,
            'name' => 'projeto.pdf',
        ]);
    }

    public function test_comment_and_file_upload_notify_activity_assignees(): void
    {
        Storage::fake('public');

        [$tenant, $admin, $contract] = $this->tenantWithUser('tenant_admin');
        $engineer = User::factory()->create();

        $tenant->memberships()->create([
            'user_id' => $engineer->id,
            'role' => 'engineer',
            'status' => 'active',
        ]);

        $contract->participants()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $engineer->id,
            'side' => 'manager',
            'role' => 'team_member',
            'status' => 'active',
        ]);

        $activity = $tenant->activities()->create([
            'contract_id' => $contract->id,
            'created_by_id' => $admin->id,
            'title' => 'Validar memoria de cálculo',
            'status' => 'todo',
            'priority' => 'normal',
        ]);
        $activity->assignees()->sync([$engineer->id]);

        $this->actingAs($admin)
            ->post(route('tenant.activities.comments.store', [$tenant, $activity]), [
                'body' => 'Favor revisar até amanhã.',
            ])
            ->assertRedirect();

        $this->actingAs($admin)
            ->post(route('tenant.activities.files.store', [$tenant, $activity]), [
                'file' => UploadedFile::fake()->create('memoria.xlsx', 90, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'),
            ])
            ->assertRedirect();

        $notifications = $engineer->notifications()->latest()->get();

        $this->assertTrue($notifications->contains(fn ($notification): bool => $notification->data['title'] === 'Novo comentário na atividade'));
        $this->assertTrue($notifications->contains(fn ($notification): bool => $notification->data['title'] === 'Novo arquivo na atividade'));
    }

    public function test_comment_and_file_upload_notify_activity_assignees_by_database_and_mail(): void
    {
        Notification::fake();
        Storage::fake('public');

        [$tenant, $admin, $contract] = $this->tenantWithUser('tenant_admin');
        $engineer = User::factory()->create();

        $tenant->memberships()->create([
            'user_id' => $engineer->id,
            'role' => 'engineer',
            'status' => 'active',
        ]);

        $contract->participants()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $engineer->id,
            'side' => 'manager',
            'role' => 'team_member',
            'status' => 'active',
        ]);

        $activity = $tenant->activities()->create([
            'contract_id' => $contract->id,
            'created_by_id' => $admin->id,
            'title' => 'Validar memoria de calculo',
            'status' => 'todo',
            'priority' => 'normal',
        ]);
        $activity->assignees()->sync([$engineer->id]);

        $this->actingAs($admin)
            ->post(route('tenant.activities.comments.store', [$tenant, $activity]), [
                'body' => 'Favor revisar ate amanha.',
            ])
            ->assertRedirect();

        $this->actingAs($admin)
            ->post(route('tenant.activities.files.store', [$tenant, $activity]), [
                'file' => UploadedFile::fake()->create('memoria.xlsx', 90, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'),
            ])
            ->assertRedirect();

        Notification::assertSentTo($engineer, ActivityCommentedNotification::class, function ($notification, array $channels): bool {
            return in_array('database', $channels, true) && in_array('mail', $channels, true);
        });
        Notification::assertSentTo($engineer, ActivityFileUploadedNotification::class, function ($notification, array $channels): bool {
            return in_array('database', $channels, true) && in_array('mail', $channels, true);
        });
    }

    public function test_assignable_users_are_prioritized_by_assignment_count_without_double_counting_primary_assignee(): void
    {
        [$tenant, $admin, $contract] = $this->tenantWithUser('tenant_admin');
        $frequentUser = User::factory()->create(['name' => 'Usuário frequente']);
        $occasionalUser = User::factory()->create(['name' => 'Usuário ocasional']);

        foreach ([$frequentUser, $occasionalUser] as $user) {
            $tenant->memberships()->create([
                'user_id' => $user->id,
                'role' => 'engineer',
                'status' => 'active',
            ]);
            $contract->participants()->create([
                'tenant_id' => $tenant->id,
                'user_id' => $user->id,
                'side' => 'manager',
                'role' => 'team_member',
                'status' => 'active',
            ]);
        }

        foreach (range(1, 3) as $sequence) {
            $activity = $tenant->activities()->create([
                'contract_id' => $contract->id,
                'assigned_to_id' => $frequentUser->id,
                'created_by_id' => $admin->id,
                'title' => "Atividade frequente {$sequence}",
                'status' => 'todo',
                'priority' => 'normal',
            ]);
            $activity->assignees()->sync([$frequentUser->id]);
        }

        $occasionalActivity = $tenant->activities()->create([
            'contract_id' => $contract->id,
            'assigned_to_id' => $occasionalUser->id,
            'created_by_id' => $admin->id,
            'title' => 'Atividade ocasional',
            'status' => 'todo',
            'priority' => 'normal',
        ]);
        $occasionalActivity->assignees()->sync([$occasionalUser->id]);

        $this->actingAs($admin)
            ->get(route('tenant.activities.index', $tenant))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where("assigneesByContract.{$contract->id}.0.id", $frequentUser->id)
                ->where("assigneesByContract.{$contract->id}.0.activity_assignment_count", 3)
                ->where("assigneesByContract.{$contract->id}.1.id", $occasionalUser->id)
                ->where("assigneesByContract.{$contract->id}.1.activity_assignment_count", 1)
            );
    }

    public function test_tenant_admin_can_create_checklist_with_ordered_items(): void
    {
        [$tenant, $admin, $contract] = $this->tenantWithUser('tenant_admin');

        $this->actingAs($admin)
            ->post(route('tenant.activities.store', $tenant), [
                'contract_id' => $contract->id,
                'activity_type' => Activity::TYPE_CHECKLIST,
                'title' => 'Checklist de fechamento',
                'description' => 'Conferir o fechamento mensal.',
                'category' => 'measurement',
                'visibility' => Activity::VISIBILITY_PUBLIC,
                'priority' => 'high',
                'checklist_items' => [
                    'Validar quantitativos',
                    'Conferir memoria de calculo',
                    'Registrar aprovacao',
                ],
            ])
            ->assertRedirect();

        $activity = Activity::where('title', 'Checklist de fechamento')->firstOrFail();

        $this->assertSame(Activity::TYPE_CHECKLIST, $activity->activity_type);
        $this->assertSame(
            ['Validar quantitativos', 'Conferir memoria de calculo', 'Registrar aprovacao'],
            $activity->checklistItems()->pluck('label')->all(),
        );

        $this->actingAs($admin)
            ->get(route('tenant.activities.index', $tenant))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('activities.0.activity_type', Activity::TYPE_CHECKLIST)
                ->has('activities.0.checklist_items', 3)
            );
    }

    public function test_assigned_user_can_complete_and_reopen_checklist_item(): void
    {
        [$tenant, $admin, $contract] = $this->tenantWithUser('tenant_admin');
        $assignee = User::factory()->create();

        $tenant->memberships()->create([
            'user_id' => $assignee->id,
            'role' => 'engineer',
            'status' => 'active',
            'activity_permissions' => [ActivityPermissions::VIEW],
        ]);
        $contract->participants()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $assignee->id,
            'side' => 'manager',
            'role' => 'team_member',
            'status' => 'active',
            'activity_permissions' => [ActivityPermissions::VIEW],
        ]);

        $activity = $tenant->activities()->create([
            'contract_id' => $contract->id,
            'created_by_id' => $admin->id,
            'activity_type' => Activity::TYPE_CHECKLIST,
            'title' => 'Checklist operacional',
            'visibility' => Activity::VISIBILITY_RESTRICTED,
            'status' => 'todo',
            'priority' => 'normal',
        ]);
        $activity->assignees()->sync([$assignee->id]);
        $item = $activity->checklistItems()->create([
            'label' => 'Executar vistoria',
            'position' => 0,
        ]);

        $this->actingAs($assignee)
            ->patch(route('tenant.activities.checklist.update', [$tenant, $activity, $item]), [
                'is_completed' => true,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('activity_checklist_items', [
            'id' => $item->id,
            'is_completed' => true,
            'completed_by_id' => $assignee->id,
        ]);
        $this->assertNotNull($item->fresh()->completed_at);

        $this->actingAs($assignee)
            ->patch(route('tenant.activities.checklist.update', [$tenant, $activity, $item]), [
                'is_completed' => false,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('activity_checklist_items', [
            'id' => $item->id,
            'is_completed' => false,
            'completed_by_id' => null,
            'completed_at' => null,
        ]);
    }

    public function test_unassigned_user_cannot_change_public_checklist_item(): void
    {
        [$tenant, $admin, $contract] = $this->tenantWithUser('tenant_admin');
        $viewer = User::factory()->create();

        $tenant->memberships()->create([
            'user_id' => $viewer->id,
            'role' => 'engineer',
            'status' => 'active',
            'activity_permissions' => [ActivityPermissions::VIEW],
        ]);
        $contract->participants()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $viewer->id,
            'side' => 'manager',
            'role' => 'team_member',
            'status' => 'active',
            'activity_permissions' => [ActivityPermissions::VIEW],
        ]);

        $activity = $tenant->activities()->create([
            'contract_id' => $contract->id,
            'created_by_id' => $admin->id,
            'activity_type' => Activity::TYPE_CHECKLIST,
            'title' => 'Checklist publico',
            'visibility' => Activity::VISIBILITY_PUBLIC,
            'status' => 'todo',
            'priority' => 'normal',
        ]);
        $item = $activity->checklistItems()->create([
            'label' => 'Etapa protegida',
            'position' => 0,
        ]);

        $this->actingAs($viewer)
            ->patch(route('tenant.activities.checklist.update', [$tenant, $activity, $item]), [
                'is_completed' => true,
            ])
            ->assertForbidden();

        $this->assertFalse($item->fresh()->is_completed);
    }

    /**
     * @return array{Tenant, User, Contract}
     */
    private function tenantWithUser(string $role): array
    {
        $tenant = Tenant::create([
            'slug' => 'teste',
            'name' => 'Empresa Teste',
            'plan' => 'starter',
            'status' => 'active',
        ]);
        $user = User::factory()->create();

        $tenant->memberships()->create([
            'user_id' => $user->id,
            'role' => $role,
            'status' => 'active',
        ]);

        $contract = $tenant->contracts()->create([
            'code' => 'CT-001',
            'name' => 'Contrato Teste',
            'status' => 'active',
        ]);

        return [$tenant, $user, $contract];
    }
}
