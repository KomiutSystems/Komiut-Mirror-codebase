<?php

declare(strict_types=1);

namespace Tests\Feature\Sacco;

use App\Auth\Roles;
use App\Models\Summary;
use App\Models\Vehicle;
use App\Models\VehicleUser;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Queues\QueueTestCase;

/**
 * The two things a summaries page has to answer that this one could not.
 *
 * WHICH BUSES EARNED NOTHING. Every figure on the page was built from
 * `Summary::query()`, so a vehicle with no summary row in the range was not a
 * zero in the table — it was absent entirely. On a 180-bus SACCO, "which twelve
 * did not work today" was structurally unanswerable, and the row you most need
 * to see was the one that was never rendered.
 *
 * AND HOW MUCH WENT THROUGH THE TILL. Cash and M-Pesa were both reported, but
 * never as a ratio — and the ratio is the number that matters. A conductor
 * keeping fares shows up as a cashless share drifting down while trips hold
 * steady, which is invisible in two absolute columns.
 *
 * Both ride the boundaries the page already enforces: a bank sees only the
 * fleet it financed, an investor only their own buses. Those are asserted here
 * too, because a panel that leaks is worse than a panel that is missing.
 */
final class SummariesIdleAndCashlessTest extends QueueTestCase
{
    private const URL = '/api/v1/auth/summaries';

    private function tookMoney(Vehicle $vehicle, float $mpesa, float $cash, ?string $on = null): void
    {
        Summary::create([
            'vehicle_id' => $vehicle->id,
            'mpesa_amount' => $mpesa,
            'cash_amount' => $cash,
            'mpesa_txn' => $mpesa > 0 ? 1 : 0,
            'cash_txn' => $cash > 0 ? 1 : 0,
            'expense_fee_amount' => '0',
            'trans_date' => $on ?? today()->toDateString(),
        ]);
    }

    /** A SACCO admin who can see the whole fleet. */
    private function saccoAdmin(array $world)
    {
        $user = $this->makeUser(['View Summaries'], $world['sacco']);
        $user->forceFill(['sacco_id' => $world['sacco']->id])->save();

        return $user->fresh();
    }

    private function summaries(): array
    {
        return $this->getJson(self::URL)->assertOk()->json();
    }

    #[Test]
    public function a_bus_that_earned_nothing_is_named(): void
    {
        // THE ROW THAT WAS NEVER RENDERED. Two buses, one working.
        $world = $this->makeWorld();
        $working = $world['vehicle'];
        $parked = $this->makeVehicle($world['sacco'], $world['owner'], $world['seat']);
        $parked->plate = 'KDZ 001Z';
        $parked->save();

        $this->tookMoney($working, 800, 200);

        Sanctum::actingAs($this->saccoAdmin($world));
        $body = $this->summaries();

        $this->assertSame(1, $body['idle']['count'], 'the parked bus has to be counted');
        $this->assertSame(
            [$parked->id],
            array_column($body['idle']['vehicles'], 'vehicle_id'),
            'and named, because "which one" is the whole question',
        );
    }

    #[Test]
    public function a_working_bus_is_not_called_idle(): void
    {
        $world = $this->makeWorld();
        $this->tookMoney($world['vehicle'], 500, 0);

        Sanctum::actingAs($this->saccoAdmin($world));
        $body = $this->summaries();

        $this->assertNotContains(
            $world['vehicle']->id,
            array_column($body['idle']['vehicles'], 'vehicle_id'),
        );
    }

    #[Test]
    public function idle_says_when_the_bus_last_collected(): void
    {
        // Deliberately looks BEYOND the window. "Earned nothing this week" and
        // "has earned nothing since 11 August" are different facts, and the
        // second is the one that gets a bus looked at.
        $world = $this->makeWorld();
        $stale = $this->makeVehicle($world['sacco'], $world['owner'], $world['seat']);
        $this->tookMoney($stale, 400, 0, today()->subDays(40)->toDateString());

        Sanctum::actingAs($this->saccoAdmin($world));
        $row = collect($this->summaries()['idle']['vehicles'])
            ->firstWhere('vehicle_id', $stale->id);

        $this->assertNotNull($row);
        $this->assertNotNull($row['last_collected_at'], 'a bus that once earned has a last-seen date');
    }

    #[Test]
    public function a_bus_that_has_never_collected_reports_no_last_seen(): void
    {
        // Null, not a fabricated date. A newly onboarded bus has no history.
        $world = $this->makeWorld();

        Sanctum::actingAs($this->saccoAdmin($world));
        $row = collect($this->summaries()['idle']['vehicles'])
            ->firstWhere('vehicle_id', $world['vehicle']->id);

        $this->assertNotNull($row);
        $this->assertNull($row['last_collected_at']);
    }

    #[Test]
    public function the_cashless_share_is_reported_per_bus_and_for_the_fleet(): void
    {
        // 750 of 1000 through the till.
        $world = $this->makeWorld();
        $this->tookMoney($world['vehicle'], 750, 250);

        Sanctum::actingAs($this->saccoAdmin($world));
        $body = $this->summaries();

        $this->assertEqualsWithDelta(75.0, (float) $body['summaries'][0]['cashless_percent'], 0.05);
        $this->assertEqualsWithDelta(75.0, (float) $body['totals']['cashless_percent'], 0.05);
    }

    #[Test]
    public function a_bus_that_collected_nothing_has_no_cashless_share(): void
    {
        // NULL, not 0%. Zero would rank an idle bus alongside one leaking every
        // shilling into a conductor's pocket, which are opposite problems.
        $world = $this->makeWorld();
        $this->tookMoney($world['vehicle'], 0, 0);

        Sanctum::actingAs($this->saccoAdmin($world));
        $body = $this->summaries();

        $this->assertNull($body['totals']['cashless_percent']);
    }

    #[Test]
    public function an_investor_is_told_about_their_own_idle_bus_and_no_one_elses(): void
    {
        // The boundary that matters most here. An investor learning the SACCO's
        // idle count would be reading the fleet's operational health through a
        // panel meant to show them their own asset.
        $world = $this->makeWorld();
        $mine = $this->makeVehicle($world['sacco'], $world['owner'], $world['seat']);
        $theirs = $this->makeVehicle($world['sacco'], $world['owner'], $world['seat']);

        // The INVESTOR ROLE is what makes ownedVehicleIds() confine at all --
        // an assignment alone leaves the caller unrestricted, which is how the
        // first version of this test passed a SACCO admin off as an owner and
        // then failed for the right reason.
        $investor = $this->makeUser(['View Summaries'], $world['sacco']);
        Role::findOrCreate(Roles::INVESTOR, 'web');
        $investor->assignRole(Roles::INVESTOR);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        VehicleUser::create([
            'user_id' => $investor->id,
            'vehicle_id' => $mine->id,
            'sacco_id' => $world['sacco']->id,
            'status' => true,
            'start_date' => now(),
        ]);

        Sanctum::actingAs($investor->fresh());
        $ids = array_column($this->summaries()['idle']['vehicles'], 'vehicle_id');

        $this->assertContains($mine->id, $ids);
        $this->assertNotContains($theirs->id, $ids, "another owner's parked bus is not their business");
        $this->assertNotContains($world['vehicle']->id, $ids);
    }
}
