<?php

declare(strict_types=1);

namespace Tests\Feature\Transactions;

use App\Enums\UserType;
use App\Models\Mpesa;
use App\Models\Transaction;
use App\Models\Vehicle;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Queues\QueueTestCase;

/**
 * transactions/mpesa reaches a bus only through the transaction that matched a
 * payment, and that EXISTS used to carry no date: a conductor's one-day poll
 * hashed the bus's whole history (11-15s cold on production), a SACCO-wide
 * count seq-scanned every transaction. Both now also bound the transaction's
 * trans_date -- which is copied from TransTime when a payment is matched, and
 * agreed within a day for all 7.8M matched payments on production -- so the
 * rows and totals must come out exactly as before.
 */
final class MpesaFeedDateBoundTest extends QueueTestCase
{
    private function paid(?Vehicle $bus, string $at, string $receipt): Mpesa
    {
        $mpesa = Mpesa::withoutGlobalScopes()->create([
            'TransID' => $receipt, 'MSISDN' => '254700000000', 'TransAmount' => '100', 'TransTime' => Carbon::parse($at),
        ]);
        if ($bus !== null) {
            Transaction::withoutGlobalScopes()->create([
                'vehicle_id' => $bus->id, 'mpesa_id' => $mpesa->id, 'amount' => 100, 'trans_date' => Carbon::parse($at),
            ]);
        }

        return $mpesa;
    }

    /** @return array{0: Vehicle, 1: Vehicle, 2: array<string, mixed>} */
    private function twoBusesAndAStranger(): array
    {
        $world = $this->makeWorld();
        $second = $this->makeVehicle($world['sacco'], $world['owner'], $world['seat']);
        $stranger = $this->makeWorld()['vehicle'];   // another SACCO's bus

        $this->paid($world['vehicle'], '2026-08-03 06:10:00', 'A1');
        $this->paid($world['vehicle'], '2026-08-03 23:59:00', 'A2');
        $this->paid($second, '2026-08-03 12:00:00', 'B1');
        $this->paid($world['vehicle'], '2026-08-02 23:59:59', 'A0');   // the day before
        $this->paid($world['vehicle'], '2026-08-04 00:00:00', 'A3');   // the next midnight
        $this->paid($stranger, '2026-08-03 12:00:00', 'S1');

        return [$world['vehicle'], $second, $world];
    }

    /** @return array{0: list<string>, 1: int} receipts shown, total reported */
    private function feed(string $query): array
    {
        $json = $this->getJson('/api/auth/transactions/mpesa?'.$query)->assertOk()->json();
        $receipts = array_column($json['mpesa'], 'TransID');
        sort($receipts);

        return [$receipts, $json['total']];
    }

    #[Test]
    public function a_saccos_day_shows_and_counts_exactly_its_own_payments(): void
    {
        [, , $world] = $this->twoBusesAndAStranger();
        Sanctum::actingAs($this->makeUser(['View Transactions'], $world['sacco']));

        $this->assertSame([['A1', 'A2', 'B1'], 3], $this->feed('date=2026-08-03'));
    }

    #[Test]
    public function one_bus_over_a_day_and_a_range(): void
    {
        [$bus, , $world] = $this->twoBusesAndAStranger();
        Sanctum::actingAs($this->makeUser(['View Transactions'], $world['sacco']));

        $this->assertSame([['A1', 'A2'], 2], $this->feed("date=2026-08-03&vehicles=[{$bus->id}]"));
        $this->assertSame([['A0', 'A1', 'A2', 'A3'], 4], $this->feed("from=2026-08-02&to=2026-08-04&vehicles=[{$bus->id}]"));
    }

    #[Test]
    public function a_super_admin_still_sees_payments_no_transaction_matched(): void
    {
        $this->twoBusesAndAStranger();
        $this->paid(null, '2026-08-03 15:00:00', 'U1');   // never matched to a bus
        $admin = $this->makeUser(['View Transactions']);
        $admin->forceFill(['type' => UserType::Superadmin])->save();
        Sanctum::actingAs($admin);

        $this->assertSame([['A1', 'A2', 'B1', 'S1', 'U1'], 5], $this->feed('date=2026-08-03'));
    }

    #[Test]
    public function every_way_of_reaching_a_bus_bounds_the_transactions_it_reads(): void
    {
        [$bus, , $world] = $this->twoBusesAndAStranger();
        Sanctum::actingAs($this->makeUser(['View Transactions'], $world['sacco']));
        $sql = [];
        DB::listen(function ($q) use (&$sql) {
            if (str_contains($q->sql, 'from "mpesas"')) {
                $sql[] = $q->sql;
            }
        });

        $this->feed('date=2026-08-03');
        $this->feed("date=2026-08-04&vehicles=[{$bus->id}]");

        $counts = array_values(array_filter($sql, fn ($s) => str_contains($s, 'count(*)')));
        $this->assertCount(2, $counts);
        foreach ($counts as $count) {
            $this->assertStringContainsString('"transactions"."trans_date" >=', $count);
        }
        $busPage = array_values(array_filter($sql, fn ($s) => ! str_contains($s, 'count(*)') && str_contains($s, '"transactions"."vehicle_id" in')));
        $this->assertNotEmpty($busPage);
        $this->assertStringContainsString('"transactions"."trans_date" >=', $busPage[0]);
    }

    #[Test]
    public function a_saccos_multi_day_count_keeps_the_plain_query(): void
    {
        // Measured on production: over a week the extra bound did not change
        // the expensive part of the plan and only added work, so it is for
        // one-day windows only. The totals are the same either way.
        [, , $world] = $this->twoBusesAndAStranger();
        Sanctum::actingAs($this->makeUser(['View Transactions'], $world['sacco']));
        $sql = [];
        DB::listen(function ($q) use (&$sql) {
            if (str_contains($q->sql, 'from "mpesas"') && str_contains($q->sql, 'count(*)')) {
                $sql[] = $q->sql;
            }
        });

        $this->assertSame([['A0', 'A1', 'A2', 'A3', 'B1'], 5], $this->feed('from=2026-08-02&to=2026-08-04'));

        $this->assertCount(1, $sql);
        $this->assertStringNotContainsString('in (select "transactions"."mpesa_id"', $sql[0]);
    }
}
