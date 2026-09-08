<?php

declare(strict_types=1);

namespace Tests\Feature\Activity;

use App\Enums\CarbonCreditType;
use App\Enums\LoyaltyTransactionType;
use App\Models\CarbonCreditTransaction;
use App\Models\LoyaltyTransaction;
use App\Models\LoyaltyAccount;
use App\Models\Sacco;
use App\Models\User;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Queues\QueueTestCase;

/**
 * GET book_a_ride/activity — the passenger's loyalty points and carbon credits
 * as ONE chronological, paged stream.
 *
 * The load-bearing property is the paging. Two ledgers merged by OFFSET are only
 * safe if the sort is a TOTAL order; the moment two rows can tie, the database is
 * free to order them differently on page 1 and page 2, and a row is returned
 * twice or never. Ties are not hypothetical here — one paid ride writes to both
 * ledgers in the same request, so rows share a timestamp to the second ACROSS
 * the two tables. every_row_appears_exactly_once_when_paging_to_the_end is the
 * test that pins it, and its fixture puts a page boundary inside a tie group on
 * every page on purpose.
 */
final class PassengerActivityFeedTest extends QueueTestCase
{
    private const URL = '/api/auth/book_a_ride/activity';

    /** Fixed, so the ordering assertions do not depend on the wall clock. */
    private function base(): Carbon
    {
        return Carbon::parse('2026-09-01 12:00:00');
    }

    /**
     * A loyalty row at an exact instant.
     *
     * created_at is stamped after the insert rather than passed to create():
     * it is not fillable, and save() on an existing model leaves a dirty
     * updated_at alone, so nothing overwrites what we set.
     */
    private function point(
        User $user,
        Sacco $sacco,
        float $value,
        LoyaltyTransactionType $type,
        Carbon $at,
        ?int $bookingId = null
    ): LoyaltyTransaction {
        $row = LoyaltyTransaction::create([
            'user_id' => $user->id,
            'sacco_id' => $sacco->id,
            'value' => $value,
            'type' => $type,
            'booking_id' => $bookingId,
        ]);

        $row->forceFill(['created_at' => $at, 'updated_at' => $at])->save();

        return $row;
    }

    private function credit(
        User $user,
        int $credits,
        CarbonCreditType $type,
        Carbon $at,
        int $spendCents = 0,
        ?string $description = null
    ): CarbonCreditTransaction {
        $row = CarbonCreditTransaction::create([
            'user_id' => $user->id,
            'credits' => $credits,
            'type' => $type,
            'spend_cents' => $spendCents,
            'description' => $description,
        ]);

        $row->forceFill(['created_at' => $at, 'updated_at' => $at])->save();

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    private function fetch(array $query = []): array
    {
        $response = $this->getJson(self::URL.'?'.http_build_query($query));
        $response->assertOk();

        return $response->json();
    }

    #[Test]
    public function both_schemes_arrive_in_one_stream_newest_first(): void
    {
        $world = $this->makeWorld();
        // sacco_id NULL, which is what a passenger is. See
        // a_passenger_with_no_sacco_still_sees_their_own_points.
        $passenger = $this->makeUser();

        $this->point($passenger, $world['sacco'], 120.5, LoyaltyTransactionType::Earned, $this->base()->subMinutes(3));
        $this->credit($passenger, 1, CarbonCreditType::Earned, $this->base()->subMinutes(2), 30000);
        $this->point($passenger, $world['sacco'], 500, LoyaltyTransactionType::Redeemed, $this->base()->subMinute());

        Sanctum::actingAs($passenger);
        $body = $this->fetch();

        $this->assertSame(['loyalty', 'carbon', 'loyalty'], array_column($body['activity'], 'scheme'));
        $this->assertSame(3, $body['total']);
        $this->assertSame(20, $body['perPage']);
        $this->assertSame(1, $body['lastPage']);
        $this->assertFalse($body['hasMore']);

        [$redeemed, $earnedCredit, $earnedPoints] = $body['activity'];

        // A points row: fractional, in points, attributed to the SACCO that owns
        // the scheme.
        $this->assertSame('points', $earnedPoints['unit']);
        $this->assertEqualsWithDelta(120.5, $earnedPoints['value'], 0.001);
        $this->assertTrue($earnedPoints['isCredit']);
        $this->assertSame('Earned on a ride', $earnedPoints['label']);
        $this->assertSame((int) $world['sacco']->id, $earnedPoints['saccoId']);
        $this->assertSame($world['sacco']->name, $earnedPoints['saccoName']);
        $this->assertNull($earnedPoints['spendKsh']);

        // A carbon row: whole credits, no SACCO, carrying the travel behind it.
        $this->assertSame('credits', $earnedCredit['unit']);
        $this->assertSame(1, $earnedCredit['value']);
        $this->assertTrue($earnedCredit['isCredit']);
        $this->assertSame('Earned by travelling', $earnedCredit['label']);
        $this->assertNull($earnedCredit['saccoId']);
        $this->assertEqualsWithDelta(300.0, $earnedCredit['spendKsh'], 0.001);

        $this->assertEqualsWithDelta(-500.0, $redeemed['value'], 0.001);
        $this->assertFalse($redeemed['isCredit']);

        // Same key set on every row, whatever scheme it came from — the app
        // builds one widget, not two.
        $keys = ['id', 'scheme', 'unit', 'value', 'isCredit', 'type', 'label',
            'description', 'saccoId', 'saccoName', 'bookingId', 'spendKsh', 'createdAt'];
        foreach ($body['activity'] as $item) {
            $this->assertSame($keys, array_keys($item));
        }
    }

    #[Test]
    public function a_row_is_never_ambiguous_about_its_unit(): void
    {
        $world = $this->makeWorld();
        $passenger = $this->makeUser();

        // The same number, 50, in each scheme. Nothing but scheme/unit tells
        // them apart, which is exactly why both are on the row.
        $this->point($passenger, $world['sacco'], 50, LoyaltyTransactionType::Earned, $this->base()->subMinutes(2));
        $this->credit($passenger, 50, CarbonCreditType::Adjusted, $this->base()->subMinute(), 0, 'Goodwill');

        Sanctum::actingAs($passenger);
        [$carbon, $points] = $this->fetch()['activity'];

        // The scheme/unit pair is asserted STRICTLY; the number is not, and that
        // distinction is the finding this test exists to record. json_encode
        // drops a whole-number float's fraction without
        // JSON_PRESERVE_ZERO_FRACTION, so 50.0 points goes out as 50 and is
        // byte-identical to 50 credits on the wire. The JSON type therefore
        // cannot carry the distinction in either direction, which is precisely
        // why scheme and unit are on every row rather than being inferable.
        $this->assertSame(['carbon', 'credits'], [$carbon['scheme'], $carbon['unit']]);
        $this->assertEqualsWithDelta(50, $carbon['value'], 0.001);
        $this->assertSame('Goodwill', $carbon['description']);
        $this->assertSame(['loyalty', 'points'], [$points['scheme'], $points['unit']],
            'the ledger is loyalty; the quantity it is counted in is points');
        $this->assertEqualsWithDelta(50, $points['value'], 0.001);
        // Points ids and carbon ids are independent sequences and collide as
        // bare integers; the scheme-qualified id is what keeps a keyed list sane.
        $this->assertNotSame($carbon['id'], $points['id']);
        $this->assertStringStartsWith('carbon:', $carbon['id']);
        $this->assertStringStartsWith('points:', $points['id']);
    }

    #[Test]
    public function a_reversal_is_a_debit_even_though_the_word_reads_like_an_undo(): void
    {
        $world = $this->makeWorld();
        $passenger = $this->makeUser();

        // Written POSITIVE on purpose. `reversed` is an earn being taken back,
        // so the type decides the sign — a client reading the stored number, or
        // guessing from the string, gets a green +80 on a debit.
        $this->point($passenger, $world['sacco'], 80, LoyaltyTransactionType::Reversed, $this->base());

        Sanctum::actingAs($passenger);
        $item = $this->fetch()['activity'][0];

        $this->assertSame('reversed', $item['type']);
        $this->assertFalse($item['isCredit']);
        $this->assertEqualsWithDelta(-80.0, $item['value'], 0.001);
        $this->assertSame('Reversed — ride refunded', $item['label']);
    }

    #[Test]
    public function a_carbon_adjustment_takes_its_direction_from_the_row(): void
    {
        $passenger = $this->makeUser();

        // CarbonCreditType has no isCredit() and must not: `adjusted` is a
        // platform correction that goes either way, so the stored integer is the
        // only thing that knows.
        $this->credit($passenger, -4, CarbonCreditType::Adjusted, $this->base()->subMinute(), 0, 'Duplicate grant clawed back');
        $this->credit($passenger, 4, CarbonCreditType::Adjusted, $this->base(), 0, 'Support goodwill');

        Sanctum::actingAs($passenger);
        [$up, $down] = $this->fetch()['activity'];

        $this->assertSame('adjusted', $up['type']);
        $this->assertTrue($up['isCredit']);
        $this->assertSame(4, $up['value']);

        $this->assertSame('adjusted', $down['type']);
        $this->assertFalse($down['isCredit']);
        $this->assertSame(-4, $down['value']);
    }

    #[Test]
    public function every_row_appears_exactly_once_when_paging_to_the_end(): void
    {
        $world = $this->makeWorld();
        $passenger = $this->makeUser();
        $other = $this->makeUser();

        // Noise in both ledgers for somebody else, at the same instants. If the
        // page query ever loses its user_id filter, the totals and the ids below
        // both move.
        $this->point($other, $world['sacco'], 999, LoyaltyTransactionType::Earned, $this->base());
        $this->credit($other, 9, CarbonCreditType::Earned, $this->base()->subMinutes(3), 90000);

        // 15 rows on only 8 DISTINCT instants: the groups at minute 0, 1, 3 and 5
        // are exact ties, and each of those ties ACROSS the two tables. At
        // per_page = 4 every page boundary lands inside one of those groups, so
        // an unstable sort has three chances to repeat or drop a row.
        //
        //   minute  0 : points, carbon, points      <- page 1 | 2 boundary after 4
        //   minute  1 : points, carbon
        //   minute  2 : carbon
        //   minute  3 : points, points, carbon, carbon
        //   minute  4 : points
        //   minute  5 : points, carbon
        //   minute  6 : carbon
        //   minute  7 : points
        $plan = [
            [0, 'p'], [0, 'c'], [0, 'p'],
            [1, 'p'], [1, 'c'],
            [2, 'c'],
            [3, 'p'], [3, 'p'], [3, 'c'], [3, 'c'],
            [4, 'p'],
            [5, 'p'], [5, 'c'],
            [6, 'c'],
            [7, 'p'],
        ];

        $fixtures = [];
        foreach ($plan as $i => [$minutesAgo, $ledger]) {
            $at = $this->base()->subMinutes($minutesAgo);

            if ($ledger === 'p') {
                $row = $this->point($passenger, $world['sacco'], 10 + $i, LoyaltyTransactionType::Earned, $at);
                $scheme = 'loyalty';
            } else {
                $row = $this->credit($passenger, 1, CarbonCreditType::Earned, $at, 100 * ($i + 1));
                $scheme = 'carbon';
            }

            $fixtures[] = [
                'key' => $scheme.':'.$row->id,
                'ts' => $at->getTimestamp(),
                'scheme' => $scheme,
                'pk' => (int) $row->id,
            ];
        }

        // The order the endpoint must produce, derived from the same total order
        // it sorts by: created_at desc, then scheme, then id desc. (scheme, id)
        // is unique across the merged stream — scheme names the table and id is
        // that table's primary key — so this admits no ties and there is exactly
        // one correct answer.
        $expected = collect($fixtures)
            ->sortBy([['ts', 'desc'], ['scheme', 'asc'], ['pk', 'desc']])
            ->pluck('key')
            ->values()
            ->all();

        Sanctum::actingAs($passenger);

        $seen = [];
        for ($page = 1; $page <= 4; $page++) {
            $body = $this->fetch(['page' => $page, 'per_page' => 4]);

            $this->assertSame(15, $body['total'], "page {$page} total");
            $this->assertSame(4, $body['lastPage']);
            $this->assertSame($page < 4, $body['hasMore'], "page {$page} hasMore");
            $this->assertCount($page < 4 ? 4 : 3, $body['activity'], "page {$page} size");

            $seen = array_merge($seen, array_column($body['activity'], 'id'));
        }

        // No duplicates: 15 slots, 15 distinct rows.
        $this->assertCount(15, $seen);
        $this->assertCount(15, array_unique($seen), 'a row was served on two different pages');

        // No gaps, and in the right order across every boundary.
        $this->assertSame($expected, $seen);

        // Past the end is empty, not an error.
        $this->assertSame([], $this->fetch(['page' => 5, 'per_page' => 4])['activity']);
    }

    #[Test]
    public function the_same_page_is_the_same_page_when_asked_twice(): void
    {
        $world = $this->makeWorld();
        $passenger = $this->makeUser();

        // Ten rows on ONE instant, five per ledger — nothing but the tiebreak
        // separates them, so an unstable sort shows up here first.
        for ($i = 0; $i < 5; $i++) {
            $this->point($passenger, $world['sacco'], 10, LoyaltyTransactionType::Earned, $this->base());
            $this->credit($passenger, 1, CarbonCreditType::Earned, $this->base(), 1000);
        }

        Sanctum::actingAs($passenger);

        $first = array_column($this->fetch(['page' => 2, 'per_page' => 3])['activity'], 'id');
        $second = array_column($this->fetch(['page' => 2, 'per_page' => 3])['activity'], 'id');

        $this->assertCount(3, $first);
        $this->assertSame($first, $second);

        // And the whole stream still holds together across pages.
        $all = [];
        for ($page = 1; $page <= 4; $page++) {
            $all = array_merge($all, array_column($this->fetch(['page' => $page, 'per_page' => 3])['activity'], 'id'));
        }
        $this->assertCount(10, array_unique($all));
    }

    #[Test]
    public function scope_narrows_the_stream_to_one_ledger(): void
    {
        $world = $this->makeWorld();
        $passenger = $this->makeUser();

        $this->point($passenger, $world['sacco'], 10, LoyaltyTransactionType::Earned, $this->base()->subMinutes(2));
        $this->point($passenger, $world['sacco'], 20, LoyaltyTransactionType::Earned, $this->base()->subMinute());
        $this->credit($passenger, 1, CarbonCreditType::Earned, $this->base(), 30000);

        Sanctum::actingAs($passenger);

        $points = $this->fetch(['scope' => 'loyalty']);
        $this->assertSame(2, $points['total']);
        $this->assertSame(['loyalty', 'loyalty'], array_column($points['activity'], 'scheme'));

        $carbon = $this->fetch(['scope' => 'carbon']);
        $this->assertSame(1, $carbon['total']);
        $this->assertSame(['carbon'], array_column($carbon['activity'], 'scheme'));

        $this->assertSame(3, $this->fetch(['scope' => 'all'])['total']);
        // Absent means all.
        $this->assertSame(3, $this->fetch()['total']);

        // Paging works the same on a single-ledger scope.
        $page2 = $this->fetch(['scope' => 'loyalty', 'page' => 2, 'per_page' => 1]);
        $this->assertCount(1, $page2['activity']);
        $this->assertEqualsWithDelta(10.0, $page2['activity'][0]['value'], 0.001);
    }

    #[Test]
    public function an_unknown_scope_is_rejected_rather_than_widened(): void
    {
        Sanctum::actingAs($this->makeUser());

        $this->getJson(self::URL.'?scope=carbon-credits')
            ->assertStatus(400)
            ->assertJsonStructure(['errors' => ['scope']]);
    }

    #[Test]
    public function per_page_is_capped_and_floored(): void
    {
        $world = $this->makeWorld();
        $passenger = $this->makeUser();

        for ($i = 0; $i < 3; $i++) {
            $this->point($passenger, $world['sacco'], 10, LoyaltyTransactionType::Earned, $this->base()->subMinutes($i));
        }

        Sanctum::actingAs($passenger);

        $this->assertSame(100, $this->fetch(['per_page' => 5000])['perPage']);
        $this->assertSame(1, $this->fetch(['per_page' => 0])['perPage']);
        $this->assertSame(1, $this->fetch(['page' => -3])['currentPage']);
    }

    #[Test]
    public function a_passenger_with_no_sacco_still_sees_their_own_points(): void
    {
        $world = $this->makeWorld();

        // The trap this endpoint had to design around: LoyaltyTransaction is a
        // BelongsToSacco model and SaccoScope fails CLOSED on a NULL sacco_id,
        // which is every passenger. Left on, the feed is empty for everyone it
        // was built for.
        $passenger = $this->makeUser();
        $this->assertNull($passenger->sacco_id);

        $this->point($passenger, $world['sacco'], 250, LoyaltyTransactionType::Earned, $this->base());

        Sanctum::actingAs($passenger);
        $body = $this->fetch();

        $this->assertSame(1, $body['total']);
        // And the SACCO name resolves too — eager-loading it off the transaction
        // would hand the relation a fresh, still-scoped Sacco query and null it.
        $this->assertSame($world['sacco']->name, $body['activity'][0]['saccoName']);
    }

    #[Test]
    public function one_passenger_never_sees_another_passengers_activity(): void
    {
        $world = $this->makeWorld();
        $mine = $this->makeUser();
        $theirs = $this->makeUser();

        $this->point($theirs, $world['sacco'], 400, LoyaltyTransactionType::Earned, $this->base());
        $this->credit($theirs, 7, CarbonCreditType::Earned, $this->base()->subMinute(), 70000);
        $this->point($mine, $world['sacco'], 15, LoyaltyTransactionType::Earned, $this->base()->subMinutes(2));

        Sanctum::actingAs($mine);
        $body = $this->fetch();

        $this->assertSame(1, $body['total']);
        $this->assertEqualsWithDelta(15.0, $body['activity'][0]['value'], 0.001);

        // A passenger with nothing yet gets an empty feed, not somebody else's.
        Sanctum::actingAs($this->makeUser());
        $empty = $this->fetch();
        $this->assertSame(0, $empty['total']);
        $this->assertSame([], $empty['activity']);
        $this->assertSame(1, $empty['lastPage']);
        $this->assertFalse($empty['hasMore']);
    }

    #[Test]
    public function a_ride_that_touched_both_ledgers_shows_up_as_two_rows_that_agree(): void
    {
        $world = $this->makeWorld();
        $passenger = $this->makeUser();

        // What one paid ride actually writes: a loyalty row and a carbon row in
        // the same request, sharing a timestamp to the second across two tables.
        $pending = $this->makeQueueStatus('Pending '.$this->nextSequence(), 'Pending');
        $queue = $this->makeQueue($world['vehicle'], $world['terminus'], $world['route'], $pending, $world['owner']);
        $booking = $this->makeBooking($queue, $passenger, $world['from'], $world['to']);

        $at = $this->base();
        $this->point($passenger, $world['sacco'], 2, LoyaltyTransactionType::Earned, $at, (int) $booking->id);
        $this->credit($passenger, 1, CarbonCreditType::Earned, $at, 20000);

        Sanctum::actingAs($passenger);
        $body = $this->fetch();

        $this->assertSame(2, $body['total']);
        // Tied to the second, so the tiebreak decides — carbon before points.
        $this->assertSame(['carbon', 'loyalty'], array_column($body['activity'], 'scheme'));
        $this->assertSame(
            $body['activity'][0]['createdAt'],
            $body['activity'][1]['createdAt'],
            'the fixture is only interesting if the two rows really do tie'
        );
        $this->assertSame((int) $booking->id, $body['activity'][1]['bookingId']);
        $this->assertNull($body['activity'][0]['bookingId']);
    }

    #[Test]
    public function the_feed_is_not_readable_without_a_token(): void
    {
        $this->getJson(self::URL)->assertStatus(401);
    }

    #[Test]
    public function the_versioned_prefix_serves_the_same_feed(): void
    {
        $world = $this->makeWorld();
        $passenger = $this->makeUser();

        // Both prefixes come from the one foreach in routes/api.php; this proves
        // the route was added inside that group and not beside it.
        $this->point($passenger, $world['sacco'], 33, LoyaltyTransactionType::Earned, $this->base());
        // A stray account row: the feed is the LEDGER, never the balance table.
        LoyaltyAccount::withoutGlobalScopes()->create([
            'user_id' => $passenger->id, 'sacco_id' => $world['sacco']->id, 'balance' => 33,
        ]);

        Sanctum::actingAs($passenger);

        $legacy = $this->getJson(self::URL)->assertOk()->json();
        $versioned = $this->getJson('/api/v1/auth/book_a_ride/activity')->assertOk()->json();

        $this->assertSame($legacy, $versioned);
        $this->assertSame(1, $versioned['total']);
    }
}
