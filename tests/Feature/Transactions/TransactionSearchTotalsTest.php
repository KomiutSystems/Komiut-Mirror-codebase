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
 * `GET transactions?search=` -- what it finds, and that the tiles describe it.
 *
 * The search runs TWICE per request in different shapes: once to fetch twenty
 * rows, and again for the count and the money tiles beside them. The list can
 * stop as soon as it has twenty; the totals cannot, so they are the expensive
 * half and they are shaped differently on purpose (the mpesas lookup is bounded
 * by TransTime, which lets it use the trigram indexes instead of joining the
 * whole date window row by row -- measured on production at 1,161ms -> 83ms).
 *
 * Two shapes meaning the same thing is exactly how a total and the rows under
 * it drift apart, which is the shape of a reconciliation dispute. So every test
 * here checks BOTH halves against each other, not just the rows.
 */
final class TransactionSearchTotalsTest extends QueueTestCase
{
    /** @return array<string, mixed> */
    private function world(): array
    {
        $world = $this->makeWorld();
        Sanctum::actingAs($this->makeUser(['View Transactions'], $world['sacco']));

        return $world;
    }

    /** A till payment by a named payer, on this world's bus. */
    private function payment(array $world, string $transId, string $first, string $last, float $amount, string $at): Transaction
    {
        $mpesa = Mpesa::withoutGlobalScopes()->create([
            'TransID' => $transId,
            'MSISDN' => '254700000000',
            'TransAmount' => (string) $amount,
            'TransTime' => Carbon::parse($at),
            'FirstName' => $first,
            'MiddleName' => 'Wanjiru',
            'LastName' => $last,
        ]);

        return Transaction::withoutGlobalScopes()->create([
            'vehicle_id' => $world['vehicle']->id,
            'mpesa_id' => $mpesa->id,
            'amount' => $amount,
            // trans_date is WRITTEN from TransTime -- the bounded lookup in the
            // totals depends on that, so the fixture must honour it.
            'trans_date' => Carbon::parse($at),
        ]);
    }

    private function search(string $term): \Illuminate\Testing\TestResponse
    {
        return $this->getJson('/api/auth/transactions?from=2026-08-01&to=2026-08-31&search='.urlencode($term));
    }

    /** The whole point: the tiles and the count must describe the rows. */
    private function assertTilesMatchRows(\Illuminate\Testing\TestResponse $res, int $rows, float $mpesa): void
    {
        $res->assertOk()
            ->assertJsonCount($rows, 'transactions')
            ->assertJsonPath('total', $rows);
        $this->assertEqualsWithDelta($mpesa, (float) $res->json('mpesa'), 0.001,
            'the M-Pesa tile must equal the M-Pesa rows the same search returned');
    }

    #[Test]
    public function a_receipt_number_finds_its_payment(): void
    {
        $world = $this->world();
        $this->payment($world, 'UHQ434J0C3', 'Grace', 'Njeri', 250, '2026-08-10 09:00');
        $this->payment($world, 'TBX991K2D7', 'Peter', 'Kamau', 400, '2026-08-11 09:00');

        $res = $this->search('UHQ434J0C3');

        $this->assertTilesMatchRows($res, 1, 250);
        $this->assertSame('UHQ434J0C3', $res->json('transactions.0.mpesa.TransID'));
    }

    #[Test]
    public function a_payer_name_finds_every_payment_they_made(): void
    {
        $world = $this->world();
        $this->payment($world, 'AAA111', 'Grace', 'Njeri', 100, '2026-08-10 09:00');
        $this->payment($world, 'BBB222', 'Grace', 'Otieno', 150, '2026-08-12 09:00');
        $this->payment($world, 'CCC333', 'Peter', 'Kamau', 900, '2026-08-13 09:00');

        // Two rows, 250 between them -- and the tile must say 250, not 1,150.
        $this->assertTilesMatchRows($this->search('Grace'), 2, 250);
    }

    #[Test]
    public function a_surname_and_a_middle_name_both_match(): void
    {
        $world = $this->world();
        $this->payment($world, 'AAA111', 'Grace', 'Njeri', 100, '2026-08-10 09:00');
        $this->payment($world, 'BBB222', 'Peter', 'Kamau', 200, '2026-08-11 09:00');

        $this->assertTilesMatchRows($this->search('Njeri'), 1, 100);
        // Every fixture carries the same middle name, so this matches both.
        $this->assertTilesMatchRows($this->search('Wanjiru'), 2, 300);
    }

    #[Test]
    public function a_number_plate_finds_that_buss_payments(): void
    {
        $world = $this->world();
        $world['vehicle']->forceFill(['plate' => 'KDX 434C'])->save();
        $this->payment($world, 'AAA111', 'Grace', 'Njeri', 100, '2026-08-10 09:00');
        $this->payment($world, 'BBB222', 'Peter', 'Kamau', 200, '2026-08-11 09:00');

        // Typed any way: the plate is normalised on both sides.
        $this->assertTilesMatchRows($this->search('KDX434C'), 2, 300);
        $this->assertTilesMatchRows($this->search('kdx-434c'), 2, 300);
    }

    #[Test]
    public function a_sacco_name_finds_its_payments(): void
    {
        $world = $this->world();
        $world['sacco']->forceFill(['name' => 'NICCO MOVERS LIMITED'])->save();
        $this->payment($world, 'AAA111', 'Grace', 'Njeri', 100, '2026-08-10 09:00');

        $this->assertTilesMatchRows($this->search('NICCO'), 1, 100);
    }

    #[Test]
    public function a_search_that_matches_nothing_returns_nothing_and_zero_tiles(): void
    {
        $world = $this->world();
        $this->payment($world, 'AAA111', 'Grace', 'Njeri', 100, '2026-08-10 09:00');

        $res = $this->search('ZZZ-no-such-thing');

        $this->assertTilesMatchRows($res, 0, 0);
    }

    #[Test]
    public function the_date_window_still_bounds_a_search(): void
    {
        $world = $this->world();
        $this->payment($world, 'AAA111', 'Grace', 'Njeri', 100, '2026-08-10 09:00');
        $this->payment($world, 'BBB222', 'Grace', 'Njeri', 500, '2026-09-10 09:00'); // outside

        // The bounded mpesas lookup is given a day of slack on each side; it
        // must never widen what the window itself already decided.
        $this->assertTilesMatchRows($this->search('Grace'), 1, 100);
    }

    #[Test]
    public function a_payment_on_the_first_morning_of_the_window_is_not_lost(): void
    {
        $world = $this->world();
        // The edge the TransTime slack exists for: a row at the very start of
        // the window, whose mpesa sits a hair before it.
        $this->payment($world, 'AAA111', 'Grace', 'Njeri', 100, '2026-08-01 00:05');

        $this->assertTilesMatchRows($this->search('Grace'), 1, 100);
    }

    #[Test]
    public function a_two_character_search_still_works(): void
    {
        $world = $this->world();
        $this->payment($world, 'AB111', 'Grace', 'Njeri', 100, '2026-08-10 09:00');
        $this->payment($world, 'XY222', 'Peter', 'Kamau', 200, '2026-08-11 09:00');

        // Below the trigram floor, so the index cannot serve it -- it must
        // still return the right answer.
        $this->assertTilesMatchRows($this->search('AB'), 1, 100);
    }
}
