<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\Contract;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\ActivityAssignedNotification;
use App\Support\ActivityPermissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
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
                'title' => 'Validar RDO',
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

    public function test_user_without_edit_or_delete_activity_permissions_cannot_change_activity(): void
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
                'priority' => 'urgent',
                'due_date' => '2026-06-20',
                'assigned_to_ids' => [$engineer->id],
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('activities', [
            'id' => $activity->id,
            'title' => 'Titulo editado',
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
