<?php

declare(strict_types=1);

namespace Tests\Feature\Routes;

use App\Models\Route;
use App\Models\SaccoRoute;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Queues\QueueTestCase;

/**
 * The passenger home screen's shortlist.
 *
 * It replaces a `const` list compiled into the app — CBD to Syokimau, Kasarani
 * and Karen. Against production, Syokimau and Karen do not exist as places at
 * all and Kasarani is on no route, so the home screen advertised three journeys
 * nobody could book and changing them needed a store release.
 *
 * Ranked by QUEUES because it is the only usage signal that exists: bookings
 * would be the natural measure and there are zero of them. A queue is a driver
 * declaring they are running a route, which is at least a real event a real
 * person caused.
 */
final class PopularRoutesTest extends QueueTestCase
{
    private const URL = '/api/v1/auth/book_a_ride/routes/popular';

    /** A route a SACCO actually runs. */
    private function runnableRoute(array $world, string $name): Route
    {
        $from = $this->makePlace($name.' from '.$this->nextSequence());
        $to = $this->makePlace($name.' to '.$this->nextSequence());
        $route = $this->makeRoute($from, $to, $world['sacco']);
        $route->forceFill(['name' => $name])->save();

        SaccoRoute::create([
            'user_id' => $world['owner']->id, 'sacco_id' => $world['sacco']->id,
            'route_id' => $route->id, 'amount' => 0, 'min_amount' => 0, 'status' => true,
        ]);

        return $route->fresh();
    }

    /**
     * Made once and reused: queue_statuses.name is unique, so a helper that
     * creates one per call blows up the moment a test queues two routes — which
     * every ranking test does by definition.
     */
    private function pendingStatus(): \App\Models\QueueStatus
    {
        return $this->pending ??= $this->makeQueueStatus('Pending', 'Pending');
    }

    private ?\App\Models\QueueStatus $pending = null;

    private function queueIt(array $world, Route $route, int $times, ?Carbon $at = null): void
    {
        $status = $this->pendingStatus();
        foreach (range(1, $times) as $i) {
            $q = $this->makeQueue($world['vehicle'], $world['terminus'], $route, $status, $world['owner']);
            if ($at !== null) {
                $q->forceFill(['created_at' => $at])->save();
            }
        }
    }

    private function popular(): array
    {
        Sanctum::actingAs($this->makeUser());

        return $this->getJson(self::URL)->assertOk()->json('routes');
    }

    #[Test]
    public function the_busiest_route_comes_first(): void
    {
        $world = $this->makeWorld();
        $quiet = $this->runnableRoute($world, 'AAA quiet');   // name sorts first
        $busy = $this->runnableRoute($world, 'ZZZ busy');     // name sorts last
        $this->queueIt($world, $busy, 3);
        $this->queueIt($world, $quiet, 1);

        $ids = array_column($this->popular(), 'id');

        $this->assertSame($busy->id, $ids[0], 'queues outrank alphabetical order');
        $this->assertSame($quiet->id, $ids[1]);
    }

    #[Test]
    public function it_never_returns_more_than_four(): void
    {
        $world = $this->makeWorld();
        foreach (range(1, 7) as $i) {
            $this->runnableRoute($world, 'Route '.$i);
        }

        $this->assertCount(4, $this->popular(), 'four is a hard cap, not a default');
    }

    #[Test]
    public function a_route_nobody_has_queued_still_appears_rather_than_leaving_it_short(): void
    {
        // An empty home screen reads as a broken app. Four routes of which two
        // are unproven reads as a small network, which is the truth.
        $world = $this->makeWorld();
        $busy = $this->runnableRoute($world, 'AAA busy');
        $this->queueIt($world, $busy, 2);
        $quiet = $this->runnableRoute($world, 'BBB never queued');

        $rows = collect($this->popular());

        // makeWorld() brings a runnable route of its own, so assert on the two
        // this test owns rather than on the size of the list.
        $this->assertSame(2, $rows->firstWhere('id', $busy->id)['trips']);
        $this->assertSame(0, $rows->firstWhere('id', $quiet->id)['trips'],
            'listed, and honestly marked as unproven');
        $this->assertSame($busy->id, $rows->first()['id'], 'the queued one leads');
    }

    #[Test]
    public function a_route_no_sacco_runs_is_never_offered(): void
    {
        // Same rule the booking search applies. A card that leads to a search
        // returning nothing is worse than no card.
        $world = $this->makeWorld();
        $this->runnableRoute($world, 'Real route');

        $orphanFrom = $this->makePlace('Orphan from');
        $orphanTo = $this->makePlace('Orphan to');
        $orphan = $this->makeRoute($orphanFrom, $orphanTo, null);
        $orphan->forceFill(['name' => 'Orphan', 'sacco_id' => null])->save();

        $ids = array_column($this->popular(), 'id');

        $this->assertNotContains($orphan->id, $ids);
    }

    #[Test]
    public function each_card_carries_the_place_ids_the_booking_search_needs(): void
    {
        // THE FIELD THAT MAKES IT A STARTING POINT. book_a_ride/routes searches
        // by from_place_id / to_place_id; a card with only names is a dead end.
        $world = $this->makeWorld();
        $route = $this->runnableRoute($world, 'With ids');

        $card = collect($this->popular())->firstWhere('id', $route->id);

        $this->assertNotNull($card, 'the route this test created is in the list');

        $this->assertSame($route->from_id, $card['from']['id']);
        $this->assertSame($route->to_id, $card['to']['id']);
        $this->assertNotEmpty($card['from']['name']);
        $this->assertNotEmpty($card['to']['name']);
    }

    #[Test]
    public function an_old_queue_no_longer_counts_as_busy(): void
    {
        // "Popular" has to mean recently, or a route abandoned months ago keeps
        // the top slot forever.
        $world = $this->makeWorld();
        $stale = $this->runnableRoute($world, 'AAA stale');
        $fresh = $this->runnableRoute($world, 'ZZZ fresh');
        $this->queueIt($world, $stale, 5, Carbon::now()->subDays(90));
        $this->queueIt($world, $fresh, 1);

        $rows = $this->popular();

        $this->assertSame($fresh->id, $rows[0]['id']);
        $this->assertSame(0, collect($rows)->firstWhere('id', $stale->id)['trips']);
    }
}
