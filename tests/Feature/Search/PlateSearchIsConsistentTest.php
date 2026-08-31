<?php

declare(strict_types=1);

namespace Tests\Feature\Search;

use App\Enums\UserType;
use App\Models\Mpesa;
use App\Models\Summary;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Vehicle;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Queues\QueueTestCase;

/**
 * One plate, typed the way people actually type it, on every screen with a
 * search box.
 *
 * THE REPORT. NICCO's finance officer went looking for KDX 434C on the new
 * system and said he could not see it. The bus was there — 129 payments that
 * day — and the vehicles list would have found it, because that screen
 * normalises a plate before comparing, so "kdk380z" finds "KDK 380Z". The
 * transactions, M-Pesa, cash and summaries screens did a raw LIKE against the
 * stored string, so the same characters found the bus on one screen and returned
 * an empty table on the next.
 *
 * To a SACCO that reads as "your system has lost my bus", and it arrived while
 * we were chasing a real payment loss — so it cost time on both.
 *
 * The defect is the DISAGREEMENT between screens, not any one query, so this is
 * written as a matrix: every screen that takes a typed plate has to answer the
 * same way, and a new screen that rolls its own LIKE fails here.
 *
 * The Super payments screens were worse than inconsistent. They used a bare
 * `like`, which on Postgres is case-SENSITIVE, so a lowercase plate matched
 * nothing there at all, whatever the spacing.
 */
final class PlateSearchIsConsistentTest extends QueueTestCase
{
    private const PLATE = 'KDX 434C';

    /** How a human types a plate. Every one of these is the same matatu. */
    public static function typings(): array
    {
        return [
            'exactly as stored' => ['KDX 434C'],
            'no space' => ['KDX434C'],
            'lower case' => ['kdx 434c'],
            'lower case, no space' => ['kdx434c'],
            'hyphenated' => ['KDX-434C'],
            'trailing space' => ['KDX 434C '],
        ];
    }

    /** @return array{0: Vehicle, 1: User} */
    private function busAndAdmin(): array
    {
        $world = $this->makeWorld();

        $bus = $world['vehicle'];
        $bus->plate = self::PLATE;
        $bus->merchant_short_code = '4560051';
        $bus->save();

        $admin = $this->makeUser(
            ['View Transactions', 'View Vehicles', 'View Summaries'],
            $world['sacco']
        );
        $admin->forceFill(['type' => UserType::Admin, 'sacco_id' => $world['sacco']->id])->save();

        return [$bus->fresh(), $admin->fresh()];
    }

    private function payment(Vehicle $bus, string $receipt = 'UHVKV4WUSE'): void
    {
        $mpesa = Mpesa::withoutGlobalScopes()->create([
            'TransID' => $receipt,
            'TransAmount' => '50',
            'TransTime' => now()->toDateTimeString(),
            'MSISDN' => '254712345678',
            'FirstName' => 'Joyce',
            'LastName' => 'Wanjiru',
            'BusinessShortCode' => '4560051',
        ]);

        Transaction::withoutGlobalScopes()->create([
            'vehicle_id' => $bus->id,
            'mpesa_id' => $mpesa->id,
            'amount' => 50,
            'trans_date' => now()->toDateTimeString(),
        ]);
    }

    #[Test]
    #[DataProvider('typings')]
    public function the_transactions_screen_finds_the_bus(string $typed): void
    {
        // The screen the report came from.
        [$bus, $admin] = $this->busAndAdmin();
        $this->payment($bus);

        Sanctum::actingAs($admin);

        $body = $this->getJson(
            '/api/v1/auth/transactions?date='.now()->toDateString().'&search='.urlencode($typed)
        )->assertOk()->json();

        $this->assertCount(1, $body['transactions'], $typed.' must find '.self::PLATE);

        // The tile beside the table has to describe the rows in it — a filtered
        // list under an unfiltered total is its own reconciliation dispute.
        $this->assertSame(50.0, (float) $body['mpesa'], $typed.' must also narrow the total');
    }

    #[Test]
    #[DataProvider('typings')]
    public function the_mpesa_screen_finds_the_bus(string $typed): void
    {
        [$bus, $admin] = $this->busAndAdmin();
        $this->payment($bus);

        Sanctum::actingAs($admin);

        $body = $this->getJson(
            '/api/v1/auth/transactions/mpesa?date='.now()->toDateString().'&search='.urlencode($typed)
        )->assertOk()->json();

        $this->assertCount(1, $body['mpesa'], $typed.' must find '.self::PLATE.' on the M-Pesa screen');
    }

    #[Test]
    #[DataProvider('typings')]
    public function the_summaries_screen_finds_the_bus(string $typed): void
    {
        [$bus, $admin] = $this->busAndAdmin();

        Summary::withoutGlobalScopes()->create([
            'vehicle_id' => $bus->id,
            'trans_date' => now()->toDateString(),
            'mpesa_amount' => 50,
            'cash_amount' => 0,
            'mpesa_txn' => 1,
            'cash_txn' => 0,
        ]);

        Sanctum::actingAs($admin);

        $body = $this->getJson(
            '/api/v1/auth/summaries?date='.now()->toDateString().'&search='.urlencode($typed)
        )->assertOk()->json();

        $this->assertCount(1, $body['summaries'], $typed.' must find '.self::PLATE.' on the summaries screen');
    }

    #[Test]
    #[DataProvider('typings')]
    public function the_vehicles_list_finds_the_bus(string $typed): void
    {
        // Already correct before this change. Asserted alongside the others
        // because the defect was the disagreement between them, so it only
        // stays fixed while both are pinned together.
        [, $admin] = $this->busAndAdmin();

        Sanctum::actingAs($admin);

        $body = $this->getJson('/api/v1/auth/vehicles?search='.urlencode($typed))->assertOk()->json();

        $this->assertCount(1, $body['vehicles'], $typed.' must find '.self::PLATE.' on the vehicles list');
    }

    #[Test]
    public function a_plate_that_is_not_there_still_finds_nothing(): void
    {
        // Stripping punctuation from both sides makes the comparison looser, so
        // the negative case matters: normalising must not turn the search box
        // into a match-anything.
        [$bus, $admin] = $this->busAndAdmin();
        $this->payment($bus);

        Sanctum::actingAs($admin);

        $body = $this->getJson(
            '/api/v1/auth/transactions?date='.now()->toDateString().'&search=KDB999Z'
        )->assertOk()->json();

        $this->assertCount(0, $body['transactions']);
        $this->assertSame(0.0, (float) $body['mpesa']);
    }

    #[Test]
    public function the_receipt_and_payer_branches_still_work(): void
    {
        // The plate is one branch of an OR across receipt, payer and SACCO.
        // Rewriting it must not cost the siblings.
        [$bus, $admin] = $this->busAndAdmin();
        $this->payment($bus, 'UHVKV4WUSE');

        Sanctum::actingAs($admin);
        $date = now()->toDateString();

        $byReceipt = $this->getJson('/api/v1/auth/transactions?date='.$date.'&search=UHVKV4WUSE')
            ->assertOk()->json();
        $this->assertCount(1, $byReceipt['transactions'], 'searching a receipt must still work');

        $byPayer = $this->getJson('/api/v1/auth/transactions?date='.$date.'&search=Joyce')
            ->assertOk()->json();
        $this->assertCount(1, $byPayer['transactions'], 'searching a payer name must still work');
    }

    #[Test]
    public function another_saccos_bus_is_not_reachable_by_plate(): void
    {
        // The search runs inside the tenant boundary. Normalising widens what
        // matches, so the boundary is worth re-asserting on the new query.
        $mine = $this->makeWorld();
        $theirs = $this->makeWorld();

        $theirBus = $theirs['vehicle'];
        $theirBus->plate = self::PLATE;
        $theirBus->save();

        $admin = $this->makeUser(['View Transactions'], $mine['sacco']);
        $admin->forceFill(['type' => UserType::Admin, 'sacco_id' => $mine['sacco']->id])->save();

        $this->payment($theirBus->fresh(), 'THEIRS01');

        Sanctum::actingAs($admin->fresh());

        $body = $this->getJson(
            '/api/v1/auth/transactions?date='.now()->toDateString().'&search=kdx434c'
        )->assertOk()->json();

        $this->assertCount(0, $body['transactions'], "another SACCO's bus must stay invisible");
    }
}
