<?php

declare(strict_types=1);

namespace Tests\Feature\Pagination;

use App\Http\Controllers\Concerns\PaginatesResults;
use App\Models\Summary;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Queues\QueueTestCase;

/**
 * Caching the paginated total.
 *
 * WHY IT IS CACHED. On the M-Pesa listing, measured against production, the two
 * halves of one request cost wildly different amounts: 3.8ms to fetch the 20
 * rows, 437ms for the COUNT(*) that renders "page 1 of N". The rows are cheap
 * because LIMIT 20 lets PostgreSQL walk an index and stop; the count has no
 * limit to stop at, so it sequentially scans 1.38M transactions to resolve the
 * tenant predicate. And it was paid again on every page turn.
 *
 * THE RISK this pins is not speed, it is CORRECTNESS OF THE KEY. A count cached
 * under a key that two different result sets can share would serve one SACCO's
 * total on another SACCO's page — a smaller leak than showing their rows, but a
 * leak, and a deeply confusing one. So most of these tests are about keys.
 */
final class CachedPageCountTest extends QueueTestCase
{
    use PaginatesResults;

    private function request(int $page = 1): Request
    {
        return Request::create('/', 'GET', ['page' => $page]);
    }

    /**
     * One takings row.
     *
     * `summaries` carries UNIQUE (vehicle_id, trans_date), so a vehicle holds at
     * most one row per day — every fixture row here needs its own date. Passing
     * null takes the next unused day, which is what most of these tests want:
     * they care how MANY rows a query counts, not which days they fall on.
     */
    private function summaryFor(int $vehicleId, ?string $date = null, float $amount = 100): void
    {
        Summary::withoutGlobalScopes()->create([
            'vehicle_id' => $vehicleId,
            'mpesa_amount' => $amount,
            'cash_amount' => 0,
            'mpesa_txn' => 1,
            'cash_txn' => 0,
            'trans_date' => $date ?? '2026-08-'.str_pad((string) (($this->nextSequence() % 27) + 1), 2, '0', STR_PAD_LEFT),
        ]);
    }

    #[Test]
    public function two_different_queries_never_share_a_total(): void
    {
        // The leak this guards against: SACCO A's count served on SACCO B's page.
        $a = $this->makeWorld();
        $b = $this->makeWorld();

        $this->summaryFor($a['vehicle']->id, '2026-08-08');
        $this->summaryFor($a['vehicle']->id, '2026-08-09');
        $this->summaryFor($b['vehicle']->id, '2026-08-08');

        $forA = $this->pageMeta(
            Summary::withoutGlobalScopes()->where('vehicle_id', $a['vehicle']->id),
            $this->request()
        );
        $forB = $this->pageMeta(
            Summary::withoutGlobalScopes()->where('vehicle_id', $b['vehicle']->id),
            $this->request()
        );

        $this->assertSame(2, $forA['total']);
        $this->assertSame(1, $forB['total'], "one query's total must never be served for another");
    }

    #[Test]
    public function two_dates_do_not_share_a_total(): void
    {
        // Dates arrive as Carbon instances. Casting them naively would render
        // both keys identically and show Tuesday's count on Monday's page.
        $w = $this->makeWorld();
        // A second bus, because one vehicle cannot hold two rows on one day.
        $second = $this->makeVehicle($w['sacco'], $w['owner'], $w['seat']);

        $this->summaryFor($w['vehicle']->id, '2026-08-08');
        $this->summaryFor($w['vehicle']->id, '2026-08-09');
        $this->summaryFor($second->id, '2026-08-09');

        $day1 = $this->pageMeta(
            Summary::withoutGlobalScopes()->whereBetween('trans_date', [
                \Carbon\Carbon::parse('2026-08-08'), \Carbon\Carbon::parse('2026-08-08 23:59:59'),
            ]),
            $this->request()
        );

        $day2 = $this->pageMeta(
            Summary::withoutGlobalScopes()->whereBetween('trans_date', [
                \Carbon\Carbon::parse('2026-08-09'), \Carbon\Carbon::parse('2026-08-09 23:59:59'),
            ]),
            $this->request()
        );

        $this->assertSame(1, $day1['total']);
        $this->assertSame(2, $day2['total']);
    }

    #[Test]
    public function the_same_query_is_counted_once_and_reused_across_pages(): void
    {
        // The actual complaint: paging 1 → 2 → 3 re-ran the same unchanged count
        // every time. This is the whole point of the change.
        $w = $this->makeWorld();
        foreach (['2026-08-08', '2026-08-09', '2026-08-10'] as $day) {
            $this->summaryFor($w['vehicle']->id, $day);
        }

        $build = fn () => Summary::withoutGlobalScopes()->where('vehicle_id', $w['vehicle']->id);

        DB::enableQueryLog();
        $this->pageMeta($build(), $this->request(1));
        $first = count(DB::getQueryLog());

        DB::flushQueryLog();
        $this->pageMeta($build(), $this->request(2));
        $this->pageMeta($build(), $this->request(3));
        $later = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertGreaterThan(0, $first, 'the first page must actually count');
        $this->assertSame(0, $later, 'pages 2 and 3 must not re-count an unchanged query');
    }

    #[Test]
    public function the_page_number_is_never_part_of_the_key(): void
    {
        // current_page varies per request and must not fragment the cache, but
        // it must still be reported correctly for the page being asked for.
        $w = $this->makeWorld();
        $this->summaryFor($w['vehicle']->id, '2026-08-08');

        $build = fn () => Summary::withoutGlobalScopes()->where('vehicle_id', $w['vehicle']->id);

        $p1 = $this->pageMeta($build(), $this->request(1));
        $p7 = $this->pageMeta($build(), $this->request(7));

        $this->assertSame($p1['total'], $p7['total']);
        $this->assertSame(1, $p1['current_page']);
        $this->assertSame(7, $p7['current_page']);
    }

    #[Test]
    public function a_zero_ttl_counts_live_every_time(): void
    {
        // The escape hatch for a caller that genuinely needs an exact number.
        $w = $this->makeWorld();
        $this->summaryFor($w['vehicle']->id, '2026-08-08');

        $build = fn () => Summary::withoutGlobalScopes()->where('vehicle_id', $w['vehicle']->id);

        $this->assertSame(1, $this->pageMeta($build(), $this->request(), 20, 0)['total']);

        $this->summaryFor($w['vehicle']->id, '2026-08-09');

        $this->assertSame(
            2,
            $this->pageMeta($build(), $this->request(), 20, 0)['total'],
            'an uncached count must see the new row immediately'
        );
    }

    #[Test]
    public function the_rows_are_never_cached_only_the_total(): void
    {
        // The staleness has to be one-sided. A total may lag by a minute; a
        // payment must never be missing from the list.
        $w = $this->makeWorld();
        $this->summaryFor($w['vehicle']->id, '2026-08-08', 100);

        $build = fn () => Summary::withoutGlobalScopes()->where('vehicle_id', $w['vehicle']->id);

        $this->pageMeta($build(), $this->request());
        $this->summaryFor($w['vehicle']->id, '2026-08-09', 999);

        $rows = $build()->orderBy('id')->take(20)->get();

        $this->assertCount(2, $rows, 'the listing itself must always be live');
        $this->assertContains(999.0, $rows->pluck('mpesa_amount')->map(fn ($a) => (float) $a)->all());
    }

    #[Test]
    public function an_empty_result_set_reports_one_page_not_zero(): void
    {
        $w = $this->makeWorld();

        $meta = $this->pageMeta(
            Summary::withoutGlobalScopes()->where('vehicle_id', $w['vehicle']->id),
            $this->request()
        );

        $this->assertSame(0, $meta['total']);
        $this->assertSame(1, $meta['last_page'], 'an empty table is still page 1 of 1');
    }
}
