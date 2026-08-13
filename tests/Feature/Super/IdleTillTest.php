<?php

declare(strict_types=1);

namespace Tests\Feature\Super;

use App\Models\AuditLog;
use App\Models\Mpesa;
use App\Models\Transaction;
use App\Models\Vehicle;
use Carbon\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Queues\QueueTestCase;

/**
 * The monitor for tills that have gone quiet.
 *
 * KDY 599G ran for a month with a live till and zero payments: its C2B
 * confirmation URL was never registered at Safaricom, so nothing reached us.
 * The record looked perfect -- only the ABSENCE of rows gave it away, and
 * nothing was watching absences. These tests exist to keep that detector honest
 * about the one case it was written for: a till that has NEVER been paid.
 */
final class IdleTillTest extends QueueTestCase
{
    private function tilled(string $shortCode, ?string $till = null): Vehicle
    {
        $sacco = $this->makeSacco();
        $vehicle = $this->makeVehicle($sacco, $this->makeUser([], $sacco), $this->makeSeat());
        $vehicle->forceFill(['merchant_short_code' => $shortCode, 'till_number' => $till])->save();

        return $vehicle;
    }

    private function lastAudit(): ?AuditLog
    {
        return AuditLog::where('action', 'tills.idle_check')->latest('id')->first();
    }

    #[Test]
    public function a_till_that_has_never_been_paid_is_reported(): void
    {
        // The KDY 599G shape. A LEFT JOIN with the window in the WHERE clause
        // would silently drop this row -- no payments means no joined rows means
        // nothing to filter -- which is exactly the vehicle worth finding.
        $vehicle = $this->tilled('4321075');

        $this->artisan('tills:check-idle --days=7')->assertExitCode(1);

        $audit = $this->lastAudit();
        $this->assertNotNull($audit);
        $this->assertSame(1, $audit->data['idle_count']);
        $this->assertContains($vehicle->plate, $audit->data['idle_plates']);
    }

    #[Test]
    public function a_till_paid_inside_the_window_is_not_reported(): void
    {
        $vehicle = $this->tilled('4321069');
        Transaction::create([
            'vehicle_id' => $vehicle->id,
            'amount' => 100,
            'trans_date' => Carbon::now()->subDay(),
        ]);

        $this->artisan('tills:check-idle --days=7')->assertExitCode(0);

        $this->assertSame(0, $this->lastAudit()->data['idle_count']);
    }

    #[Test]
    public function a_till_whose_last_payment_predates_the_window_is_reported(): void
    {
        // Earning once in 2024 is not earning. The window is what makes a
        // stopped till distinguishable from a working one.
        $vehicle = $this->tilled('4321071');
        Transaction::create([
            'vehicle_id' => $vehicle->id,
            'amount' => 100,
            'trans_date' => Carbon::now()->subDays(30),
        ]);

        $this->artisan('tills:check-idle --days=7')->assertExitCode(1);

        $this->assertContains($vehicle->plate, $this->lastAudit()->data['idle_plates']);
    }

    #[Test]
    public function a_vehicle_with_no_till_at_all_is_ignored(): void
    {
        // It cannot take money, so silence tells us nothing.
        $sacco = $this->makeSacco();
        $this->makeVehicle($sacco, $this->makeUser([], $sacco), $this->makeSeat());

        $this->artisan('tills:check-idle --days=7')->assertExitCode(0);

        $this->assertSame(0, $this->lastAudit()->data['idle_count']);
    }

    #[Test]
    public function money_for_a_shortcode_no_vehicle_claims_is_reported(): void
    {
        // The mirror image: the payment DID reach us, and we could not attribute
        // it. Those already land in a log nobody reads.
        Mpesa::create([
            'TransID' => 'ABC'.$this->nextSequence(),
            'MSISDN' => '254700111222',
            'TransAmount' => '150',
            'TransTime' => Carbon::now()->subHours(2),
            'BusinessShortCode' => '9999999',
        ]);

        $this->artisan('tills:check-idle --days=7')->assertExitCode(1);

        $this->assertSame(1, $this->lastAudit()->data['unmatched_shortcodes']);
    }

    #[Test]
    public function money_for_a_shortcode_we_do_know_is_not_flagged(): void
    {
        $vehicle = $this->tilled('4321073');
        Transaction::create([
            'vehicle_id' => $vehicle->id,
            'amount' => 150,
            'trans_date' => Carbon::now()->subHour(),
        ]);
        Mpesa::create([
            'TransID' => 'ABC'.$this->nextSequence(),
            'MSISDN' => '254700111222',
            'TransAmount' => '150',
            'TransTime' => Carbon::now()->subHour(),
            'BusinessShortCode' => '4321073',
        ]);

        $this->artisan('tills:check-idle --days=7')->assertExitCode(0);
    }

    #[Test]
    public function a_clean_run_still_leaves_evidence(): void
    {
        // "We checked and found nothing" is worth being able to prove later --
        // otherwise a monitor that silently stopped running looks identical to
        // one that keeps coming back clean.
        $this->artisan('tills:check-idle')->assertExitCode(0);

        $audit = $this->lastAudit();
        $this->assertNotNull($audit);
        $this->assertSame(0, $audit->data['idle_count']);
        $this->assertSame(0, $audit->data['unmatched_shortcodes']);
    }
}
