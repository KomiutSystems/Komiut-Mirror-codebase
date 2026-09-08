<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Enums\NotificationType;
use App\Notifications\PlatformNotification;
use Tests\Feature\Queues\QueueTestCase;

/**
 * A notification arriving live must parse with the same code as one fetched
 * from history.
 *
 * Laravel's BroadcastNotificationCreated does
 * `array_merge($this->data, ['id' => ..., 'type' => $this->broadcastType()])`,
 * and broadcastType() defaults to get_class($notification). So `type` — the
 * field a client switches on — arrived over the socket as
 * "App\Notifications\PlatformNotification" while the REST copy of the same
 * notification said "trip". A client handling stored notifications correctly
 * would have fallen through on every realtime one.
 */
final class BroadcastPayloadShapeTest extends QueueTestCase
{
    private function notification(): PlatformNotification
    {
        $n = new PlatformNotification(
            type: NotificationType::Trip,
            title: 'Booking confirmed',
            message: 'Your booking is confirmed and paid.',
            referenceId: '41',
        );
        $n->id = 'a1b2c3d4-0000-0000-0000-000000000000';

        return $n;
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function the_socket_carries_our_type_not_the_php_class(): void
    {
        $payload = $this->notification()->broadcastWith();

        $this->assertSame('trip', $payload['type'],
            'a client switching on type must see "trip", never the notification class');
        $this->assertStringNotContainsString('PlatformNotification', (string) $payload['type']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function the_socket_item_has_the_same_keys_as_the_rest_item(): void
    {
        // NotificationResource is what GET /notifications returns. One shape, so
        // the app needs one parser.
        $payload = $this->notification()->broadcastWith();

        foreach (['id', 'title', 'message', 'type', 'referenceId', 'organizationId', 'isRead', 'createdAt'] as $key) {
            $this->assertArrayHasKey($key, $payload, "the socket payload is missing {$key}");
        }
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function a_live_notification_is_unread_and_carries_its_id(): void
    {
        $payload = $this->notification()->broadcastWith();

        $this->assertFalse($payload['isRead'], 'it fires the instant it is created');
        $this->assertSame('a1b2c3d4-0000-0000-0000-000000000000', $payload['id'],
            'the id is what the client posts back to /notifications/{id}/read');
        $this->assertNotEmpty($payload['createdAt']);
    }
}
