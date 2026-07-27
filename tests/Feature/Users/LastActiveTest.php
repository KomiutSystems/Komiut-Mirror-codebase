<?php

declare(strict_types=1);

namespace Tests\Feature\Users;

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Queues\QueueTestCase;

/**
 * App\Http\Middleware\TouchLastActive — "when was this account last online".
 *
 * Covers the two behaviours the dashboards depend on: it records activity, and
 * it does so without corrupting `updated_at` (which must keep meaning "edited").
 */
final class LastActiveTest extends QueueTestCase
{
    #[Test]
    public function an_authenticated_request_stamps_last_active_at(): void
    {
        $world = $this->makeWorld();
        $user = $this->makeUser([], $world['sacco']);
        $this->assertNull($user->last_active_at);

        Sanctum::actingAs($user);
        $this->getJson('/api/auth/settings/gender')->assertOk();

        $this->assertNotNull($user->fresh()->last_active_at);
    }

    #[Test]
    public function activity_does_not_bump_updated_at(): void
    {
        $world = $this->makeWorld();
        $user = $this->makeUser([], $world['sacco']);
        $originalUpdatedAt = $user->fresh()->updated_at;

        Sanctum::actingAs($user);
        $this->getJson('/api/auth/settings/gender')->assertOk();

        $fresh = $user->fresh();
        $this->assertNotNull($fresh->last_active_at);
        $this->assertEquals(
            $originalUpdatedAt->timestamp,
            $fresh->updated_at->timestamp,
            'last_active_at must not disturb updated_at'
        );
    }

    #[Test]
    public function a_recent_stamp_is_not_rewritten_on_every_request(): void
    {
        $world = $this->makeWorld();
        $user = $this->makeUser([], $world['sacco']);

        // Already active 1 minute ago — inside the staleness window.
        $recent = now()->subMinute();
        User::withoutGlobalScopes()->whereKey($user->id)->update(['last_active_at' => $recent]);

        Sanctum::actingAs($user->fresh());
        $this->getJson('/api/auth/settings/gender')->assertOk();

        $this->assertSame(
            $recent->timestamp,
            $user->fresh()->last_active_at->timestamp,
            'a stamp newer than the staleness window should not be rewritten'
        );
    }

    #[Test]
    public function a_stale_stamp_is_refreshed(): void
    {
        $world = $this->makeWorld();
        $user = $this->makeUser([], $world['sacco']);

        $stale = now()->subHours(3);
        User::withoutGlobalScopes()->whereKey($user->id)->update(['last_active_at' => $stale]);

        Sanctum::actingAs($user->fresh());
        $this->getJson('/api/auth/settings/gender')->assertOk();

        $this->assertTrue(
            $user->fresh()->last_active_at->gt($stale),
            'a stamp older than the staleness window should be refreshed'
        );
    }

    #[Test]
    public function an_unauthenticated_request_stamps_nobody(): void
    {
        $world = $this->makeWorld();
        $user = $this->makeUser([], $world['sacco']);

        $this->getJson('/api/auth/settings/gender')->assertStatus(401);

        $this->assertNull($user->fresh()->last_active_at);
    }
}
