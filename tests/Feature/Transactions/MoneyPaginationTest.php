<?php

declare(strict_types=1);

namespace Tests\Feature\Transactions;

use App\Models\Mpesa;
use App\Models\Transaction;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Queues\QueueTestCase;

/**
 * Paging the two big money listings: 1.31M transactions, 1.33M mpesas.
 *
 * Three properties, all of which the old code got wrong:
 *
 *  - the order is TOTAL, so no row appears on two pages or on none. Ordering by
 *    the timestamp alone was not: up to 10 prod rows share one `TransTime`.
 *  - the count is computed ONCE per filter set, not once per page. It grows with
 *    the date range (~90ms for a day, ~900ms for a month) and does not change
 *    while you page, so repeating it per page was pure cost.
 *  - a cursor pages by seek, so depth costs nothing: measured on prod at 7ms
 *    against 1,324ms for the equivalent OFFSET.
 */
final class MoneyPaginationTest extends QueueTestCase
{
    private function world(): array
    {
        $world = $this->makeWorld();
        Sanctum::actingAs($this->makeUser(['View Transactions'], $world['sacco']));

        return $world;
    }

    /** $count payments all sharing ONE timestamp — the tie the sort must break. */
    private function tiedTransactions(array $world, string $at, int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            Transaction::withoutGlobalScopes()->create([
                'vehicle_id' => $world['vehicle']->id,
                'amount' => 100,
                'trans_date' => Carbon::parse($at),
            ]);
        }
    }

    #[Test]
    public function tied_timestamps_do_not_repeat_or_lose_a_row_across_pages(): void
    {
        $world = $this->world();
        $this->tiedTransactions($world, '2026-08-03 09:00:00', 30);

        $seen = [];
        foreach ([1, 2] as $page) {
            $ids = $this->getJson('/api/auth/transactions?date=2026-08-03&page='.$page)
                ->assertOk()->json('transactions.*.id');
            $seen = array_merge($seen, $ids);
        }

        $this->assertCount(30, $seen, 'every row appeared exactly once');
        $this->assertSame(count($seen), count(array_unique($seen)), 'no row appeared twice');
    }

    #[Test]
    public function a_cursor_walks_the_same_rows_as_offset_paging(): void
    {
        $world = $this->world();
        $this->tiedTransactions($world, '2026-08-03 09:00:00', 30);

        $byOffset = [];
        foreach ([1, 2] as $page) {
            $byOffset = array_merge($byOffset, $this->getJson('/api/auth/transactions?date=2026-08-03&page='.$page)
                ->assertOk()->json('transactions.*.id'));
        }

        $byCursor = [];
        $cursor = null;
        do {
            $body = $this->getJson('/api/auth/transactions?date=2026-08-03'.($cursor ? '&cursor='.$cursor : ''))
                ->assertOk()->json();
            $byCursor = array_merge($byCursor, array_column($body['transactions'], 'id'));
            $cursor = $body['next_cursor'] ?? null;
        } while ($cursor !== null);

        $this->assertSame($byOffset, $byCursor);
    }

    #[Test]
    public function the_last_page_reports_no_next_cursor(): void
    {
        $world = $this->world();
        $this->tiedTransactions($world, '2026-08-03 09:00:00', 5);

        $this->getJson('/api/auth/transactions?date=2026-08-03')
            ->assertOk()
            ->assertJsonPath('next_cursor', null);
    }

    #[Test]
    public function a_stale_or_malformed_cursor_returns_the_first_page_rather_than_an_error(): void
    {
        $world = $this->world();
        $this->tiedTransactions($world, '2026-08-03 09:00:00', 3);

        $this->getJson('/api/auth/transactions?date=2026-08-03&cursor=not-a-real-cursor')
            ->assertOk()
            ->assertJsonCount(3, 'transactions');
    }

    #[Test]
    public function the_count_is_computed_once_and_reused_while_paging(): void
    {
        $world = $this->world();
        $this->tiedTransactions($world, '2026-08-03 09:00:00', 25);

        $this->getJson('/api/auth/transactions?date=2026-08-03')
            ->assertOk()->assertJsonPath('total', 25);

        // A row added after the count was cached does not change it within the
        // TTL. That is the point: the number is memoised per filter set, so
        // paging a wide range costs one count instead of one per page.
        $this->tiedTransactions($world, '2026-08-03 09:00:00', 5);

        $this->getJson('/api/auth/transactions?date=2026-08-03&page=2')
            ->assertOk()->assertJsonPath('total', 25);

        // A DIFFERENT filter is a different key, and counts on its own.
        $this->getJson('/api/auth/transactions?from=2026-08-01&to=2026-08-31')
            ->assertOk()->assertJsonPath('total', 30);
    }

    #[Test]
    public function the_mpesa_screen_pages_the_same_way(): void
    {
        $world = $this->world();

        for ($i = 0; $i < 25; $i++) {
            $mpesa = Mpesa::withoutGlobalScopes()->create([
                'TransID' => 'M'.$this->nextSequence(),
                'MSISDN' => '254700000000',
                'TransAmount' => '100',
                'TransTime' => Carbon::parse('2026-08-03 09:00:00'),
                'FirstName' => 'Ivy',
            ]);
            Transaction::withoutGlobalScopes()->create([
                'vehicle_id' => $world['vehicle']->id,
                'mpesa_id' => $mpesa->id,
                'amount' => 100,
                'trans_date' => Carbon::parse('2026-08-03 09:00:00'),
            ]);
        }

        $first = $this->getJson('/api/auth/transactions/mpesa?date=2026-08-03')->assertOk()->json();

        $this->assertCount(20, $first['mpesa']);
        $this->assertSame(25, $first['total']);
        $this->assertNotNull($first['next_cursor']);

        $second = $this->getJson('/api/auth/transactions/mpesa?date=2026-08-03&cursor='.$first['next_cursor'])
            ->assertOk()->json();

        $this->assertCount(5, $second['mpesa']);
        $this->assertNull($second['next_cursor']);

        $ids = array_merge(array_column($first['mpesa'], 'id'), array_column($second['mpesa'], 'id'));
        $this->assertSame(25, count(array_unique($ids)), 'no row seen twice across the cursor walk');
    }

    #[Test]
    public function the_mpesa_screen_honours_a_date_range(): void
    {
        $world = $this->world();

        foreach (['2026-08-01 09:00', '2026-08-05 09:00', '2026-08-20 09:00'] as $at) {
            $mpesa = Mpesa::withoutGlobalScopes()->create([
                'TransID' => 'M'.$this->nextSequence(),
                'MSISDN' => '254700000000',
                'TransAmount' => '100',
                'TransTime' => Carbon::parse($at),
            ]);
            Transaction::withoutGlobalScopes()->create([
                'vehicle_id' => $world['vehicle']->id,
                'mpesa_id' => $mpesa->id,
                'amount' => 100,
                'trans_date' => Carbon::parse($at),
            ]);
        }

        $this->getJson('/api/auth/transactions/mpesa?from=2026-08-01&to=2026-08-05')
            ->assertOk()
            ->assertJsonPath('total', 2);
    }

    #[Test]
    public function midnight_belongs_to_one_day_only_on_the_mpesa_screen(): void
    {
        $world = $this->world();

        foreach (['2026-08-05 12:00', '2026-08-06 00:00'] as $at) {
            $mpesa = Mpesa::withoutGlobalScopes()->create([
                'TransID' => 'M'.$this->nextSequence(),
                'MSISDN' => '254700000000',
                'TransAmount' => '100',
                'TransTime' => Carbon::parse($at),
            ]);
            Transaction::withoutGlobalScopes()->create([
                'vehicle_id' => $world['vehicle']->id,
                'mpesa_id' => $mpesa->id,
                'amount' => 100,
                'trans_date' => Carbon::parse($at),
            ]);
        }

        $this->getJson('/api/auth/transactions/mpesa?date=2026-08-05')
            ->assertOk()
            ->assertJsonPath('total', 1);
    }
}
