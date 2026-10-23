<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Notification;
use Tests\TestCase;

class NotificationReadTest extends TestCase
{
    use RefreshDatabase;

    public function test_notification_feed_requires_authentication_and_only_returns_the_current_users_alerts(): void
    {
        $this->getJson(route('notifications.index'))->assertUnauthorized();
        $user = User::factory()->create();
        $other = User::factory()->create();
        $user->notify(new TestDatabaseNotification);
        $other->notify(new TestDatabaseNotification);
        $own = $user->notifications()->sole();
        $this->actingAs($user)->getJson(route('notifications.index', ['user_id' => $other->id]))->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonCount(1, 'items')->assertJsonPath('unread_count', 1)
            ->assertJsonPath('items.0.id', $own->id)->assertJsonPath('items.0.unread', true);
        $this->patch(route('notifications.read', $own->id))->assertNoContent();
        $this->getJson(route('notifications.index'))->assertOk()->assertJsonPath('unread_count', 0)->assertJsonPath('items.0.unread', false);
    }

    public function test_notification_feed_caps_the_items_and_preserves_the_total_unread_count(): void
    {
        $user = User::factory()->create();
        foreach (range(1, 55) as $index) {
            $user->notify(new TestDatabaseNotification);
        }
        $this->actingAs($user)->getJson(route('notifications.index'))->assertOk()
            ->assertJsonCount(50, 'items')->assertJsonPath('unread_count', 55);
    }

    public function test_user_can_mark_own_notification_as_read(): void
    {
        $user = User::factory()->create();
        $user->notify(new TestDatabaseNotification);
        $notification = $user->unreadNotifications()->firstOrFail();

        $this->actingAs($user)
            ->patch(route('notifications.read', $notification->id))
            ->assertNoContent();

        $this->assertNotNull($notification->fresh()->read_at);
        $this->assertSame(0, $user->fresh()->unreadNotifications()->count());
    }

    public function test_user_cannot_mark_another_users_notification_as_read(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $owner->notify(new TestDatabaseNotification);
        $notification = $owner->unreadNotifications()->firstOrFail();

        $this->actingAs($otherUser)
            ->patch(route('notifications.read', $notification->id))
            ->assertNotFound();

        $this->assertNull($notification->fresh()->read_at);
    }

    public function test_hidden_notifications_are_marked_as_read_without_changing_visible_items(): void
    {
        $user = User::factory()->create();

        foreach (range(1, 75) as $index) {
            $user->notify(new TestDatabaseNotification);
        }

        $visibleIds = $user->notifications()->latest()->limit(50)->pluck('id')->all();

        $this->actingAs($user)
            ->patchJson(route('notifications.hidden.read'), ['visible_ids' => $visibleIds])
            ->assertOk()
            ->assertJson(['marked_count' => 25]);

        $this->assertSame(50, $user->fresh()->unreadNotifications()->count());
        $this->assertSame(
            $visibleIds,
            $user->unreadNotifications()->latest()->pluck('id')->all(),
        );
    }
}

class TestDatabaseNotification extends Notification
{
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'title' => 'Notificacao de teste',
            'body' => 'Conteudo da notificacao.',
        ];
    }
}
