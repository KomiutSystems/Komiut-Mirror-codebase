<?php

declare(strict_types=1);

namespace Tests\Feature\Termini;

use App\Models\SaccoTerminus;
use App\Models\TerminusUser;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Queues\QueueTestCase;

/**
 * The SACCO-side terminus list, GET /api/auth/routes/termini.
 *
 * It narrowed to `sacco_termini` rows for the caller's SACCO and that table
 * holds ZERO rows for all 48 SACCOs, so every SACCO's terminus screen was
 * blank. It also narrowed by `terminus_users` rows for the CURRENT USER — inert
 * only because that table is empty too, and a screen-emptying trap the moment
 * anyone wrote one. Same failure shape, and the same three-tier answer, as
 * App\Services\Driver\AvailableTermini.
 */
final class SaccoTerminusListTest extends QueueTestCase
{
    #[Test]
    public function a_sacco_with_no_configured_links_still_gets_a_usable_list(): void
    {
        $sacco = $this->makeSacco();
        $terminus = $this->makeTerminus($this->makePlace('Nairobi CBD'));

        // The production case exactly: termini exist, sacco_termini is empty.
        $this->assertSame(0, SaccoTerminus::withoutGlobalScopes()->count());

        Sanctum::actingAs($this->makeUser(['View Termini'], $sacco));

        $response = $this->getJson('/api/auth/routes/termini')->assertOk();

        $this->assertNotEmpty($response->json('termini'));
        $this->assertContains($terminus->id, collect($response->json('termini'))->pluck('id')->all());
        // pageMeta is taken after the narrowing, so total describes what is shown.
        $this->assertSame(1, $response->json('total'));
    }

    #[Test]
    public function configured_links_still_win_over_the_fallback(): void
    {
        $sacco = $this->makeSacco();
        $owner = $this->makeUser([], $sacco);
        $linked = $this->makeTerminus($this->makePlace('Linked'));
        $other = $this->makeTerminus($this->makePlace('Unlinked'));

        SaccoTerminus::create([
            'sacco_id' => $sacco->id,
            'terminus_id' => $linked->id,
            'user_id' => $owner->id,
            'geofence_radius' => 150,
        ]);

        Sanctum::actingAs($this->makeUser(['View Termini'], $sacco));

        $ids = collect($this->getJson('/api/auth/routes/termini')->assertOk()->json('termini'))->pluck('id');

        $this->assertTrue($ids->contains($linked->id));
        $this->assertFalse($ids->contains($other->id));
    }

    #[Test]
    public function a_saccos_route_origins_are_preferred_over_every_terminus(): void
    {
        $world = $this->makeWorld();
        // makeWorld puts a terminus at the route origin and files a sacco_route.
        $elsewhere = $this->makeTerminus($this->makePlace('Somewhere else '.$this->nextSequence()));

        Sanctum::actingAs($this->makeUser(['View Termini'], $world['sacco']));

        $ids = collect($this->getJson('/api/auth/routes/termini')->assertOk()->json('termini'))->pluck('id');

        $this->assertTrue($ids->contains($world['terminus']->id));
        $this->assertFalse($ids->contains($elsewhere->id));
    }

    #[Test]
    public function a_suspended_terminus_is_never_offered_by_the_fallback(): void
    {
        $sacco = $this->makeSacco();
        $live = $this->makeTerminus($this->makePlace('Live'));
        $suspended = $this->makeTerminus($this->makePlace('Suspended'));
        $suspended->forceFill(['status' => false])->save();

        Sanctum::actingAs($this->makeUser(['View Termini'], $sacco));

        $ids = collect($this->getJson('/api/auth/routes/termini')->assertOk()->json('termini'))->pluck('id');

        $this->assertTrue($ids->contains($live->id));
        $this->assertFalse($ids->contains($suspended->id));
    }

    #[Test]
    public function a_terminus_users_row_no_longer_narrows_the_sacco_list(): void
    {
        $sacco = $this->makeSacco();
        $pinned = $this->makeTerminus($this->makePlace('Pinned'));
        $unpinned = $this->makeTerminus($this->makePlace('Unpinned'));

        $user = $this->makeUser(['View Termini'], $sacco);
        // The trap: one row here used to empty this SACCO-level screen of every
        // OTHER terminus, for this user only.
        TerminusUser::create(['user_id' => $user->id, 'terminus_id' => $pinned->id, 'status' => true]);

        Sanctum::actingAs($user);

        $ids = collect($this->getJson('/api/auth/routes/termini')->assertOk()->json('termini'))->pluck('id');

        $this->assertTrue($ids->contains($pinned->id));
        $this->assertTrue($ids->contains($unpinned->id));
    }
}
