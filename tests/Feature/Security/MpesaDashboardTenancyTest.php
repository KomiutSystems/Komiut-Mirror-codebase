<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Enums\UserType;
use App\Models\Mpesa;
use App\Models\Sacco;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Vehicle;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Queues\QueueTestCase;

/**
 * MpesaDashboardController::saccoConstraint() returned null for TWO different
 * situations — "this caller is a superadmin" and "this caller has no SACCO" —
 * and every consumer read null as the first one. Since SaccoScope also skips a
 * user with no home SACCO, a saccoless non-super account holding the route's
 * permission got the superadmin view of both endpoints: the platform-wide user
 * count, every SACCO's payments, and a `coverage` block reporting BOTH banks'
 * vehicle and till totals.
 *
 * The two questions are now asked separately and "no SACCO" fails closed.
 */
final class MpesaDashboardTenancyTest extends QueueTestCase
{
    private function vehicleIn(Sacco $sacco, ?string $financier, ?string $till = '4321087'): Vehicle
    {
        $vehicle = $this->makeVehicle($sacco, $this->makeUser([], $sacco), $this->makeSeat());
        $vehicle->update(['financier' => $financier, 'till_number' => $till]);

        return $vehicle;
    }

    private function payment(Vehicle $vehicle, string $transId, float $amount): void
    {
        $mpesa = Mpesa::create([
            'TransID' => $transId,
            'MSISDN' => '254700111222',
            'TransAmount' => $amount,
            'TransTime' => now(),
            'FirstName' => 'Test', 'LastName' => 'Payer',
            'BusinessShortCode' => '5557936',
        ]);

        Transaction::create([
            'mpesa_id' => $mpesa->id,
            'vehicle_id' => $vehicle->id,
            'amount' => $amount,
            'trans_date' => now(),
        ]);
    }

    private function superadmin(array $permissions): User
    {
        $user = $this->makeUser($permissions);
        $user->forceFill(['type' => UserType::Superadmin])->save();

        return $user;
    }

    #[Test]
    public function a_saccoless_caller_gets_no_stats_rather_than_the_platform_totals(): void
    {
        $sacco = $this->makeSacco();
        $this->payment($this->vehicleIn($sacco, 'NCBA'), 'SOMEONE-ELSES', 500);

        Sanctum::actingAs($this->makeUser(['View Transactions']));   // no sacco, not super

        $stats = $this->getJson('/api/v1/auth/mpesa/stats')->assertOk()->json();

        $this->assertSame(0.0, (float) $stats['mpesa_today']);
        $this->assertSame(0, $stats['tills_count']);
        $this->assertSame(0, $stats['users_count'], 'The platform-wide user count is a superadmin figure.');
        $this->assertSame([], $stats['recent_transactions']);
    }

    #[Test]
    public function a_saccoless_caller_gets_no_tills_and_no_bank_coverage(): void
    {
        $sacco = $this->makeSacco();
        $this->vehicleIn($sacco, 'NCBA');
        $this->vehicleIn($sacco, 'coop-bank');

        Sanctum::actingAs($this->makeUser(['View Payment Settings']));

        $body = $this->getJson('/api/v1/auth/mpesa/tills')->assertOk()->json();

        $this->assertSame([], $body['tills']);
        $this->assertSame([], $body['coverage'], 'coverage groups by financier with no constraint of its own.');
        $this->assertSame(0, $body['total']);
    }

    #[Test]
    public function coverage_for_a_sacco_admin_stays_bounded_by_saccoscope(): void
    {
        // A REGRESSION GUARD, not the fix: for a caller who has a SACCO,
        // `coverage` was already bounded, because Vehicle carries SaccoScope and
        // the GROUP BY runs on an Eloquent builder. The hole the fix closes is
        // the caller with NO sacco — see the test above. This pins the half that
        // already worked so the fail-closed gate does not quietly break it.
        $mine = $this->makeSacco();
        $theirs = $this->makeSacco();
        $this->vehicleIn($mine, 'NCBA');
        $this->vehicleIn($theirs, 'NCBA');
        $this->vehicleIn($theirs, 'coop-bank');

        Sanctum::actingAs($this->makeUser(['View Payment Settings'], $mine));

        $coverage = $this->getJson('/api/v1/auth/mpesa/tills')->assertOk()->json('coverage');

        $this->assertCount(1, $coverage, 'Only the financiers present in this SACCO\'s own fleet.');
        $this->assertSame('NCBA', $coverage[0]['financier']);
        $this->assertSame(1, $coverage[0]['vehicles']);
    }

    #[Test]
    public function a_superadmin_still_reads_across_every_sacco(): void
    {
        // The fail-closed rule keys on "no SACCO", and a superadmin has no
        // sacco_id either — they must not be caught by it.
        $mine = $this->makeSacco();
        $theirs = $this->makeSacco();
        $this->payment($this->vehicleIn($mine, 'NCBA'), 'ONE', 100);
        $this->payment($this->vehicleIn($theirs, 'coop-bank'), 'TWO', 200);

        Sanctum::actingAs($this->superadmin(['View Transactions', 'View Payment Settings']));

        $stats = $this->getJson('/api/v1/auth/mpesa/stats')->assertOk()->json();
        $this->assertSame(300.0, (float) $stats['mpesa_today']);
        $this->assertSame(2, $stats['tills_count']);
        $this->assertGreaterThan(0, $stats['users_count']);

        $coverage = $this->getJson('/api/v1/auth/mpesa/tills')->assertOk()->json('coverage');
        $this->assertCount(2, $coverage, 'Both banks — this is the platform view.');
    }
}
