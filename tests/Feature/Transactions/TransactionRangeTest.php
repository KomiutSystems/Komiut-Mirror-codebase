<?php

declare(strict_types=1);

namespace Tests\Feature\Transactions;

use App\Models\Transaction;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Queues\QueueTestCase;

/**
 * `GET transactions` over a date RANGE, with honest pagination.
 *
 * Two gaps that the dashboard worked around in the browser:
 *
 * RANGE. `date` resolved to a single day, so "this week" was assembled by
 * issuing ONE REQUEST PER DAY and merging client-side — ~30 round trips for a
 * month, hard-capped at 31 days because it could not go further. The dashboard
 * already sends `from`/`to` alongside `date`, so honouring them here collapses
 * the fan-out to one call with no frontend change.
 *
 * META. The response carried no `total`/`last_page`, unlike its siblings
 * transactions/mpesa and transactions/cash. The page could not tell whether
 * another existed, so it guessed a page count by summing the M-Pesa and Cash tab
 * totals — numbers describing two OTHER endpoints — and the Next button led
 * nowhere.
 */
final class TransactionRangeTest extends QueueTestCase
{
    /**
     * @return array{world: array<string, mixed>}
     */
    private function world(): array
    {
        $world = $this->makeWorld();
        Sanctum::actingAs($this->makeUser(['View Transactions'], $world['sacco']));

        return $world;
    }

    private function transactionOn(array $world, string $date, float $amount = 100): Transaction
    {
        return Transaction::withoutGlobalScopes()->create([
            'vehicle_id' => $world['vehicle']->id,
            'amount' => $amount,
            'trans_date' => Carbon::parse($date),
        ]);
    }

    #[Test]
    public function a_range_returns_every_day_it_spans(): void
    {
        $world = $this->world();
        $this->transactionOn($world, '2026-08-01 09:00');
        $this->transactionOn($world, '2026-08-05 09:00');
        $this->transactionOn($world, '2026-08-09 09:00');
        $this->transactionOn($world, '2026-08-20 09:00'); // outside

        $this->getJson('/api/auth/transactions?from=2026-08-01&to=2026-08-09')
            ->assertOk()
            ->assertJsonCount(3, 'transactions')
            ->assertJsonPath('total', 3);
    }

    #[Test]
    public function the_last_day_of_a_range_is_included(): void
    {
        $world = $this->world();
        // 23:59 on the final day is the row a half-open [from, to) gets wrong
        // if `to` is not advanced past midnight.
        $this->transactionOn($world, '2026-08-09 23:59');

        $this->getJson('/api/auth/transactions?from=2026-08-01&to=2026-08-09')
            ->assertOk()
            ->assertJsonPath('total', 1);
    }

    #[Test]
    public function a_single_day_still_works_and_excludes_the_next_midnight(): void
    {
        $world = $this->world();
        $this->transactionOn($world, '2026-08-05 12:00');
        // Exactly midnight the following day belongs to the 6th, not the 5th —
        // an inclusive BETWEEN would count it into both and inflate the month.
        $this->transactionOn($world, '2026-08-06 00:00');

        $this->getJson('/api/auth/transactions?date=2026-08-05')
            ->assertOk()
            ->assertJsonPath('total', 1);
    }

    #[Test]
    public function a_range_wins_when_the_dashboard_sends_date_and_range_together(): void
    {
        $world = $this->world();
        $this->transactionOn($world, '2026-08-01 09:00');
        $this->transactionOn($world, '2026-08-05 09:00');

        // The dashboard sends both, so the day this starts being honoured it
        // needs no client change.
        $this->getJson('/api/auth/transactions?date=2026-08-05&from=2026-08-01&to=2026-08-05')
            ->assertOk()
            ->assertJsonPath('total', 2);
    }

    #[Test]
    public function a_reversed_range_reads_as_one_day_rather_than_nothing(): void
    {
        $world = $this->world();
        $this->transactionOn($world, '2026-08-05 09:00');

        $this->getJson('/api/auth/transactions?from=2026-08-05&to=2026-08-01')
            ->assertOk()
            ->assertJsonPath('total', 1);
    }

    #[Test]
    public function pagination_meta_describes_the_filtered_set_and_survives_paging(): void
    {
        $world = $this->world();
        for ($i = 0; $i < 25; $i++) {
            $this->transactionOn($world, '2026-08-03 09:00');
        }
        $this->transactionOn($world, '2026-09-15 09:00'); // outside the filter

        $first = $this->getJson('/api/auth/transactions?from=2026-08-01&to=2026-08-31')
            ->assertOk()
            ->assertJsonCount(20, 'transactions')
            ->assertJsonPath('total', 25)
            ->assertJsonPath('per_page', 20)
            ->assertJsonPath('current_page', 1)
            ->assertJsonPath('last_page', 2)
            ->json();

        $this->assertArrayHasKey('mpesa', $first, 'the original payload keys survive');
        $this->assertArrayHasKey('cash', $first);

        $this->getJson('/api/auth/transactions?from=2026-08-01&to=2026-08-31&page=2')
            ->assertOk()
            ->assertJsonCount(5, 'transactions')
            ->assertJsonPath('total', 25)
            ->assertJsonPath('current_page', 2);
    }
}
