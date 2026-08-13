<?php

declare(strict_types=1);

namespace Tests\Feature\Driver;

use App\Enums\UserType;
use App\Models\Sacco;
use App\Models\Terminus;
use App\Models\TerminusUser;
use App\Models\User;
use App\Services\Driver\AvailableTermini;
use Illuminate\Support\Collection;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Queues\QueueTestCase;

/**
 * The stages a driver can queue at.
 *
 * POST /user returns `termini` and that list is the driver app's ONLY source
 * for its terminus picker. It read `terminus_users` directly — a per-driver
 * assignment table this system never writes to. Frankfurt had 450 crew
 * assignments and ZERO terminus rows, so every driver would have opened the
 * picker empty and been unable to join a queue: no trip, no bookings, no fare.
 *
 * The same failure shape as the empty TerminusSeeder and the SACCO-scoped
 * ExpenseFee before it — a reference table nobody populates, behind a screen
 * that silently offers nothing instead of erroring. So the property worth
 * pinning is not which source wins, it is that the list is NEVER EMPTY when a
 * terminus exists at all.
 */
final class AvailableTerminiTest extends QueueTestCase
{
    private function driver(?Sacco $sacco = null): User
    {
        $driver = $this->makeUser([], $sacco);
        $driver->forceFill(['type' => UserType::Driver])->save();

        return $driver;
    }

    private function resolve(User $driver): Collection
    {
        return app(AvailableTermini::class)->forDriver($driver);
    }

    #[Test]
    public function a_driver_with_no_assignment_still_gets_somewhere_to_queue(): void
    {
        // The Frankfurt case exactly: termini exist, terminus_users is empty.
        $sacco = $this->makeSacco();
        $terminus = $this->makeTerminus($this->makePlace('Nairobi CBD '.$this->nextSequence()));

        $this->assertSame(0, TerminusUser::count(), 'The table this used to read is empty.');

        $result = $this->resolve($this->driver($sacco));

        $this->assertNotEmpty($result, 'An empty picker means the driver can never join a queue.');
        $this->assertTrue($result->contains(fn ($row) => (int) $row['terminus']->id === (int) $terminus->id));
    }

    #[Test]
    public function an_explicit_assignment_still_wins(): void
    {
        // If somebody has deliberately pinned a driver to a stage, honour it
        // rather than handing them the whole country.
        $sacco = $this->makeSacco();
        $mine = $this->makeTerminus($this->makePlace('Mine '.$this->nextSequence()));
        $this->makeTerminus($this->makePlace('Somewhere else '.$this->nextSequence()));

        $driver = $this->driver($sacco);
        TerminusUser::create(['user_id' => $driver->id, 'terminus_id' => $mine->id, 'status' => true]);

        $result = $this->resolve($driver);

        $this->assertCount(1, $result);
        $this->assertSame((int) $mine->id, (int) $result->first()['terminus']->id);
    }

    #[Test]
    public function a_saccos_route_origins_are_preferred_over_every_terminus(): void
    {
        // Where a matatu's routes start IS where its driver queues.
        $sacco = $this->makeSacco();
        $owner = $this->makeUser([], $sacco);
        $from = $this->makePlace('Origin '.$this->nextSequence());
        $to = $this->makePlace('Destination '.$this->nextSequence());
        $ours = $this->makeTerminus($from);
        $unrelated = $this->makeTerminus($this->makePlace('Unrelated '.$this->nextSequence()));

        $route = $this->makeRoute($from, $to);
        $this->makeSaccoRoute($sacco, $route, $owner, 200);

        $result = $this->resolve($this->driver($sacco));

        $ids = $result->map(fn ($row) => (int) $row['terminus']->id)->all();
        $this->assertContains((int) $ours->id, $ids);
        $this->assertNotContains((int) $unrelated->id, $ids,
            "A driver should be offered their own SACCO's stages, not every stage in the country.");
    }

    #[Test]
    public function a_sacco_whose_origins_have_no_terminus_falls_through(): void
    {
        // An empty result there is a real answer but not a USABLE one, so it
        // must fall through rather than reproduce the empty picker.
        $sacco = $this->makeSacco();
        $owner = $this->makeUser([], $sacco);
        $from = $this->makePlace('No terminus here '.$this->nextSequence());
        $to = $this->makePlace('Elsewhere '.$this->nextSequence());
        $this->makeSaccoRoute($sacco, $this->makeRoute($from, $to), $owner, 200);

        $fallback = $this->makeTerminus($this->makePlace('Somewhere '.$this->nextSequence()));

        $result = $this->resolve($this->driver($sacco));

        $this->assertTrue($result->contains(fn ($row) => (int) $row['terminus']->id === (int) $fallback->id));
    }

    #[Test]
    public function a_suspended_terminus_is_never_offered(): void
    {
        $sacco = $this->makeSacco();
        $live = $this->makeTerminus($this->makePlace('Live '.$this->nextSequence()));
        $closed = $this->makeTerminus($this->makePlace('Closed '.$this->nextSequence()));
        $closed->forceFill(['status' => false])->save();

        $ids = $this->resolve($this->driver($sacco))->map(fn ($r) => (int) $r['terminus']->id)->all();

        $this->assertContains((int) $live->id, $ids);
        $this->assertNotContains((int) $closed->id, $ids);
    }

    #[Test]
    public function the_endpoint_returns_them_in_the_shape_the_app_reads(): void
    {
        // The app reads termini[].terminus.id and .place — changing the envelope
        // breaks the picker just as thoroughly as leaving it empty.
        $sacco = $this->makeSacco();
        $this->makeTerminus($this->makePlace('CBD '.$this->nextSequence()));

        Sanctum::actingAs($this->driver($sacco));

        $this->postJson('/api/v1/auth/user')
            ->assertOk()
            ->assertJsonStructure(['termini' => [['terminus' => ['id', 'name', 'place_id']]]]);
    }

    #[Test]
    public function it_survives_a_system_with_no_termini_at_all(): void
    {
        // Nothing to offer is still not an error — the picker is empty and the
        // driver is told to contact their SACCO, rather than the endpoint 500ing.
        Terminus::query()->delete();

        Sanctum::actingAs($this->driver($this->makeSacco()));

        $this->postJson('/api/v1/auth/user')->assertOk()->assertJsonPath('termini', []);
    }
}
