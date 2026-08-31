<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use App\Enums\UserType;
use App\Models\Mpesa;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Vehicle;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Queues\QueueTestCase;

/**
 * Money that reached no bus is not takings.
 *
 * WHAT WAS BEING COUNTED. NICCO sweeps every till to the bank at 03:00, and
 * those sweeps come back in as C2B on nine collection shortcodes that belong to
 * no vehicle. C2bPaymentRecorder cannot attribute them, so it writes the
 * transaction with a null vehicle_id — correctly, because the row is the only
 * record the money exists. Every takings figure then summed it.
 *
 * On 2026-08-31 that was 26 rows worth KES 483,268 against real takings of
 * KES 1,183,771: an unscoped dashboard reported KES 1,667,039, about 41% of it
 * the SACCO's own money counted a second time on its way to the bank. The same
 * pattern every day — 341k to 489k.
 *
 * WHO SAW IT, AND WHY THAT IS THE DANGEROUS PART. Not SACCO admins and not
 * investors: sacco_id is reached THROUGH the vehicle, so a null vehicle_id
 * already failed their whereHas. It was the UNSCOPED reads — the superadmin
 * dashboard, the platform payments screen, Pulse — which is exactly where
 * nobody has a conductor's book to check the number against.
 *
 * NOT MERELY EXCLUDED. A payment on an unknown shortcode also has to become
 * VISIBLE, or removing it from takings just moves it from wrong to invisible.
 * The unreconciled view counted only M-Pesa rows with no transaction at all, so
 * these rows were absent there while present in gross volume — the worst of both
 * places at once.
 *
 * Deliberately NOT excluded: fares paid from Airtel Money or a bank app. They
 * arrive as "Organization To Organization Transfer" like the sweeps do, and the
 * first read of this data mistook them for sweeps. They land on a real bus till
 * and attribute normally — 137 of them for NICCO on 31 Aug, KES 8,510 of genuine
 * fares. The rule is attribution, never the transaction type.
 */
final class SweepsAreNotTakingsTest extends QueueTestCase
{
    /**
     * Saccoless, so nothing narrows the reads — which is the whole point: these
     * are the views that had no tenant filter to accidentally save them.
     *
     * `View Transactions` is granted explicitly because being a superadmin does
     * not bypass the permission middleware on the dashboard route.
     */
    private function superAdmin(): User
    {
        $user = $this->makeUser(['View Transactions']);
        $user->forceFill(['type' => UserType::Superadmin, 'sacco_id' => null])->save();
        Permission::findOrCreate('View Platform Notifications', 'web');
        $user->givePermissionTo('View Platform Notifications');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }

    private function saccoAdmin(array $world): User
    {
        $u = $this->makeUser(['View Transactions'], $world['sacco']);
        $u->forceFill(['type' => UserType::Admin, 'sacco_id' => $world['sacco']->id])->save();

        return $u->fresh();
    }

    /** A fare: lands on a bus, attributes normally. */
    private function fare(Vehicle $bus, string $receipt, float $amount, string $type = 'Customer Merchant Payment'): void
    {
        $mpesa = Mpesa::withoutGlobalScopes()->create([
            'TransID' => $receipt,
            'TransAmount' => (string) $amount,
            'TransTime' => now()->toDateTimeString(),
            'MSISDN' => '254712345678',
            'FirstName' => 'Joyce',
            'BusinessShortCode' => '4560051',
            'TransactionType' => $type,
        ]);

        Transaction::withoutGlobalScopes()->create([
            'mpesa_id' => $mpesa->id,
            'vehicle_id' => $bus->id,
            'amount' => $amount,
            'trans_date' => now()->toDateTimeString(),
        ]);
    }

    /**
     * A till-to-bank sweep: real money, real receipt, but on a collection
     * shortcode belonging to no vehicle — so the recorder leaves vehicle_id null.
     */
    private function sweep(string $receipt, float $amount): Mpesa
    {
        $mpesa = Mpesa::withoutGlobalScopes()->create([
            'TransID' => $receipt,
            'TransAmount' => (string) $amount,
            'TransTime' => now()->toDateTimeString(),
            'MSISDN' => '254700000000',
            'FirstName' => 'NICCO MOVERS- KDN 458N',
            'BusinessShortCode' => '5339736',
            'TransactionType' => 'Organization To Organization Transfer',
        ]);

        Transaction::withoutGlobalScopes()->create([
            'mpesa_id' => $mpesa->id,
            'vehicle_id' => null,
            'amount' => $amount,
            'trans_date' => now()->toDateTimeString(),
        ]);

        return $mpesa;
    }

    #[Test]
    public function an_unscoped_dashboard_does_not_count_the_sweep(): void
    {
        // The headline defect, at the scale it actually occurred: one day's real
        // fares against one night's sweep.
        $world = $this->makeWorld();
        $this->fare($world['vehicle'], 'FARE01', 150);
        $this->sweep('SWEEP01', 24710);

        Sanctum::actingAs($this->superAdmin());

        $body = $this->getJson('/api/v1/auth/dashboard')->assertOk()->json();

        $this->assertSame(150.0, (float) $body['today']['total'], 'the sweep is not takings');
        $this->assertSame(150.0, (float) $body['today']['mpesa']);
    }

    #[Test]
    public function the_period_total_does_not_count_the_sweep_either(): void
    {
        // `mpesa`/`cash`/`totals` are the selected period and are what the old
        // dashboard tiles render, so they need the same guarantee as `today`.
        $world = $this->makeWorld();
        $this->fare($world['vehicle'], 'FARE01', 150);
        $this->sweep('SWEEP01', 24710);

        Sanctum::actingAs($this->superAdmin());

        $body = $this->getJson('/api/v1/auth/dashboard')->assertOk()->json();

        $this->assertSame(150.0, (float) $body['totals']);
        $this->assertSame(150.0, (float) $body['period']['total']);
    }

    #[Test]
    public function a_fare_paid_from_airtel_or_a_bank_still_counts(): void
    {
        // These carry the SAME TransactionType as the sweeps and are ordinary
        // fares — a passenger paying from an Airtel wallet or a bank app. The
        // rule has to be attribution, never the type string, or a real day's
        // takings loses every non-M-Pesa rail on the fleet.
        $world = $this->makeWorld();
        $this->fare($world['vehicle'], 'AIRTEL01', 200, 'Organization To Organization Transfer');
        $this->fare($world['vehicle'], 'FULIZA01', 80, 'OD Payment Transfer');

        Sanctum::actingAs($this->superAdmin());

        $body = $this->getJson('/api/v1/auth/dashboard')->assertOk()->json();

        $this->assertSame(280.0, (float) $body['today']['total'], 'these reached a bus, so they are takings');
    }

    #[Test]
    public function the_sacco_dashboard_is_unchanged(): void
    {
        // A SACCO admin was never affected — sacco_id is reached through the
        // vehicle, so the sweep already failed their whereHas. Asserted so the
        // fix cannot quietly change the number Henry reconciles against.
        $world = $this->makeWorld();
        $this->fare($world['vehicle'], 'FARE01', 150);
        $this->sweep('SWEEP01', 24710);

        Sanctum::actingAs($this->saccoAdmin($world));

        $body = $this->getJson('/api/v1/auth/dashboard')->assertOk()->json();

        $this->assertSame(150.0, (float) $body['today']['total']);
    }

    #[Test]
    public function the_transactions_screen_never_listed_the_sweep(): void
    {
        // This screen inner-joins vehicles, so it was already correct. Pinned
        // because it is the screen a SACCO reconciles on.
        $world = $this->makeWorld();
        $this->fare($world['vehicle'], 'FARE01', 150);
        $this->sweep('SWEEP01', 24710);

        Sanctum::actingAs($this->saccoAdmin($world));

        $body = $this->getJson('/api/v1/auth/transactions?date='.now()->toDateString())
            ->assertOk()->json();

        $this->assertCount(1, $body['transactions']);
        $this->assertSame(150.0, (float) $body['mpesa']);
    }

    #[Test]
    public function platform_gross_volume_does_not_count_the_sweep(): void
    {
        $world = $this->makeWorld();
        $this->fare($world['vehicle'], 'FARE01', 150);
        $this->sweep('SWEEP01', 24710);

        Sanctum::actingAs($this->superAdmin());

        $body = $this->getJson('/api/v1/super/payments/summary')->assertOk()->json();

        $this->assertSame(150.0, (float) $body['gross_volume']);
        $this->assertSame(1, (int) $body['settled'], 'the sweep is not a settled payment either');
    }

    #[Test]
    public function the_sweep_shows_up_as_unreconciled_rather_than_vanishing(): void
    {
        // THE OTHER HALF. Removing it from takings without surfacing it here
        // would turn a wrong number into a missing one. Unreconciled has two
        // shapes — no transaction row, or a transaction row with no vehicle —
        // and only the first used to count.
        $world = $this->makeWorld();
        $this->fare($world['vehicle'], 'FARE01', 150);
        $this->sweep('SWEEP01', 24710);

        Sanctum::actingAs($this->superAdmin());

        $body = $this->getJson('/api/v1/super/payments/summary')->assertOk()->json();

        $this->assertSame(1, (int) $body['unreconciled'], 'a payment that reached no bus must be visible somewhere');
        $this->assertSame(24710.0, (float) $body['unreconciled_value']);
    }

    #[Test]
    public function a_payment_with_no_transaction_at_all_is_still_unreconciled(): void
    {
        // The original shape must keep working alongside the new one.
        $world = $this->makeWorld();
        $this->fare($world['vehicle'], 'FARE01', 150);

        Mpesa::withoutGlobalScopes()->create([
            'TransID' => 'ORPHAN01',
            'TransAmount' => '500',
            'TransTime' => now()->toDateTimeString(),
            'MSISDN' => '254700000001',
            'FirstName' => 'Nobody',
            'BusinessShortCode' => '9999999',
            'TransactionType' => 'Customer Merchant Payment',
        ]);

        Sanctum::actingAs($this->superAdmin());

        $body = $this->getJson('/api/v1/super/payments/summary')->assertOk()->json();

        $this->assertSame(1, (int) $body['unreconciled']);
    }
}
