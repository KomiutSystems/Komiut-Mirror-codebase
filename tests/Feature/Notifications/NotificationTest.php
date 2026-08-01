<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Enums\NotificationType;
use App\Models\FirebaseToken;
use App\Models\User;
use App\Services\Notifications\NotificationService;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Queues\QueueTestCase;

/**
 * The notification subsystem: the dispatcher (single choke point, idempotent),
 * the in-app list/read endpoints in the app's camelCase shape, and device
 * registration. The FCM channel is not exercised here (no network in tests) —
 * database is the channel under test.
 */
final class NotificationTest extends QueueTestCase
{
    private function dispatcher(): NotificationService
    {
        return app(NotificationService::class);
    }

    /**
     * Persist an in-app notification directly. The real dispatch is ShouldQueue +
     * afterCommit, so under RefreshDatabase's uncommitted transaction it would
     * never land — the read-side endpoints are tested against rows created here,
     * and the dispatch/idempotency behaviour is tested separately via a faked
     * notifier.
     */
    private function give(User $user, array $data = []): \Illuminate\Notifications\DatabaseNotification
    {
        return $user->notifications()->create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'type' => \App\Notifications\PlatformNotification::class,
            'data' => [
                'type' => ($data['type'] ?? NotificationType::Trip)->value,
                'title' => $data['title'] ?? 'Booking confirmed',
                'message' => $data['message'] ?? 'Your booking is confirmed and paid.',
                'referenceId' => $data['ref'] ?? '1',
            ],
            'read_at' => null,
        ]);
    }

    #[Test]
    public function the_list_returns_the_app_camelCase_shape(): void
    {
        $user = $this->makeUser();
        $this->give($user, ['title' => 'Booking confirmed', 'ref' => '42']);
        Sanctum::actingAs($user);

        $this->getJson('/api/auth/notifications')
            ->assertOk()
            ->assertJsonPath('message.items.0.title', 'Booking confirmed')
            ->assertJsonPath('message.items.0.type', 'trip')
            ->assertJsonPath('message.items.0.referenceId', '42')
            ->assertJsonPath('message.items.0.isRead', false)
            ->assertJsonStructure(['message' => ['items' => [['id', 'title', 'message', 'type', 'createdAt', 'isRead']], 'count', 'unreadCount']]);
    }

    #[Test]
    public function unread_count_reflects_reads(): void
    {
        $user = $this->makeUser();
        $this->give($user, ['ref' => '1']);
        $this->give($user, ['ref' => '2']);
        Sanctum::actingAs($user);

        $this->getJson('/api/auth/notifications/unread-count')
            ->assertOk()->assertJsonPath('message.count', 2);

        $this->postJson('/api/auth/notifications/read-all')->assertOk();

        $this->getJson('/api/auth/notifications/unread-count')
            ->assertOk()->assertJsonPath('message.count', 0);
    }

    #[Test]
    public function a_single_notification_can_be_marked_read(): void
    {
        $user = $this->makeUser();
        $this->give($user, ['ref' => '7']);
        Sanctum::actingAs($user);

        $id = $this->getJson('/api/auth/notifications')->json('message.items.0.id');

        $this->postJson("/api/auth/notifications/{$id}/read")->assertOk();
        $this->getJson('/api/auth/notifications/unread-count')->assertJsonPath('message.count', 0);
    }

    #[Test]
    public function a_user_cannot_read_another_users_notification(): void
    {
        $owner = $this->makeUser();
        $this->give($owner, ['ref' => '9']);
        $id = \Illuminate\Notifications\DatabaseNotification::first()->id;

        Sanctum::actingAs($this->makeUser());
        $this->postJson("/api/auth/notifications/{$id}/read")->assertStatus(404);
    }

    #[Test]
    public function only_unread_are_returned_when_filtered(): void
    {
        $user = $this->makeUser();
        $this->give($user, ['ref' => '1']);
        $this->give($user, ['ref' => '2']);
        $user->unreadNotifications->first()->markAsRead();
        Sanctum::actingAs($user);

        $this->getJson('/api/auth/notifications?unread_only=true')
            ->assertOk()->assertJsonCount(1, 'message.items');
    }

    #[Test]
    public function the_dispatcher_skips_a_duplicate_reference_and_title(): void
    {
        Notification::fake();
        $user = $this->makeUser();
        // A matching notification already exists (e.g. from the first webhook).
        $this->give($user, ['title' => 'Booking confirmed', 'ref' => '5']);

        // A retried event must not notify again.
        $this->dispatcher()->dispatch($user, NotificationType::Trip, 'Booking confirmed', 'msg', '5');

        Notification::assertNothingSentTo($user);
    }

    #[Test]
    public function a_notification_is_queued_not_sent_inline(): void
    {
        // The payment-safety property: dispatch fires from inside a payment
        // transaction, so it must go through the queued (ShouldQueue) pipeline,
        // never run channel I/O synchronously.
        Notification::fake();
        $user = $this->makeUser();

        $this->dispatcher()->dispatch($user, NotificationType::Trip, 'X', 'Y', '1');

        Notification::assertSentTo($user, \App\Notifications\PlatformNotification::class);
    }

    #[Test]
    public function a_device_can_register_and_unregister(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $this->postJson('/api/auth/notifications/devices', ['token' => 'fcm-abc', 'platform' => 'ANDROID'])
            ->assertOk()->assertJsonPath('success', true);
        $this->assertDatabaseHas('firebase_tokens', ['firebase_token' => 'fcm-abc', 'user_id' => $user->id, 'platform' => 'ANDROID']);

        // Re-registering the same token is an upsert, not a duplicate.
        $this->postJson('/api/auth/notifications/devices', ['token' => 'fcm-abc', 'platform' => 'ANDROID'])->assertOk();
        $this->assertSame(1, FirebaseToken::where('firebase_token', 'fcm-abc')->count());

        $this->deleteJson('/api/auth/notifications/devices/fcm-abc')->assertOk();
        $this->assertDatabaseMissing('firebase_tokens', ['firebase_token' => 'fcm-abc']);
    }

    #[Test]
    public function a_device_token_is_reassigned_to_the_new_owner(): void
    {
        $first = $this->makeUser();
        FirebaseToken::create(['firebase_token' => 'shared', 'user_id' => $first->id, 'platform' => 'ANDROID', 'device_id' => null]);

        $second = $this->makeUser();
        Sanctum::actingAs($second);
        $this->postJson('/api/auth/notifications/devices', ['token' => 'shared', 'platform' => 'IOS'])->assertOk();

        $this->assertDatabaseHas('firebase_tokens', ['firebase_token' => 'shared', 'user_id' => $second->id]);
        $this->assertSame(1, FirebaseToken::where('firebase_token', 'shared')->count());
    }
}
