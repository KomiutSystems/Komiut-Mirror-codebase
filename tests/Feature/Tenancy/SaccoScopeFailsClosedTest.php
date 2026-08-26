<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Enums\UserType;
use App\Models\Concerns\BelongsToSacco;
use App\Models\Sacco;
use App\Models\Summary;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Tests\Feature\Queues\QueueTestCase;

/**
 * SaccoScope, for a caller who has no SACCO of their own.
 *
 * This is the case the scope used to hand the whole platform to. `sacco_id`
 * NULL took an early `return`, applying NO filter, so the tenant boundary ran
 * backwards: the less you belonged to, the more you saw. Measured on production
 * before the fix — passenger account id 3, zero permissions, sacco_id NULL, read
 * 5,033 summaries worth KES 78,223,947 across 18 SACCOs, 1.3M transactions and
 * all 895 vehicles. 6,388 accounts had a NULL sacco_id, so that was every
 * passenger on the platform.
 *
 * The exemption was there for a real reason: passengers browse across SACCOs to
 * book a ride. So the fix is not "deny everything" but "deny by default, and let
 * the book-a-ride catalogue say so out loud." These tests hold both halves — the
 * money stays shut AND the passenger app keeps working.
 */
final class SaccoScopeFailsClosedTest extends QueueTestCase
{
    /**
     * Tables a tenantless caller must NEVER read. Takings, credentials, staff
     * and member lists, and the parcel book — which carries sender and recipient
     * names, phone numbers and ID numbers, and has no user filter of its own.
     *
     * @var array<int, class-string<Model>>
     */
    private const CLOSED = [
        \App\Models\Summary::class,
        \App\Models\Transaction::class,
        \App\Models\Mpesa::class,
        \App\Models\Cash::class,
        \App\Models\CashSubmission::class,
        \App\Models\MpesaPaymentSetting::class,
        \App\Models\Parcel::class,
        \App\Models\VehicleLocation::class,
        \App\Models\Invoice::class,
        \App\Models\InvoicePayment::class,
        \App\Models\Subscription::class,
        \App\Models\BankTillRequest::class,
        \App\Models\SaccoUser::class,
        \App\Models\VehicleUser::class,
        \App\Models\SaccoVehicle::class,
        \App\Models\QrcodePayment::class,
        \App\Models\Point::class,
        \App\Models\LoyaltyAccount::class,
        \App\Models\LoyaltyTransaction::class,
        \App\Models\VehicleExpenseAndFee::class,
    ];

    /**
     * The complete opt-in list. Not a sample — the assertion is equality, so
     * adding an eighth model to the browsable set has to be a deliberate edit
     * here, with a reviewer looking at it. That is the entire safety property:
     * the list is short, and it stays short.
     *
     * @var array<int, class-string<Model>>
     */
    private const OPEN = [
        \App\Models\Booking::class,
        \App\Models\Queue::class,
        \App\Models\RouteFare::class,
        \App\Models\Sacco::class,
        \App\Models\SaccoRoute::class,
        \App\Models\SaccoTerminus::class,
        \App\Models\Vehicle::class,
    ];

    /** A passenger: authenticated, no SACCO, no permissions. */
    private function passenger(): User
    {
        $u = $this->makeUser([], null);
        $u->type = UserType::Passenger;
        $u->save();

        return $u->fresh();
    }

    private function takings(int $vehicleId, float $mpesa): void
    {
        Summary::withoutGlobalScopes()->create([
            'vehicle_id' => $vehicleId,
            'mpesa_amount' => $mpesa,
            'cash_amount' => 0,
            'mpesa_txn' => 1,
            'cash_txn' => 0,
            'trans_date' => now()->toDateString(),
        ]);
    }

    #[Test]
    public function a_passenger_reads_no_takings_at_all(): void
    {
        // The measured leak, with real rows rather than a SQL shape: two SACCOs
        // taking money, one passenger belonging to neither.
        $a = $this->makeWorld();
        $b = $this->makeWorld();

        $this->takings($a['vehicle']->id, 12500);
        $this->takings($b['vehicle']->id, 3000);

        $this->assertSame(2, Summary::withoutGlobalScopes()->count(), 'fixture check');

        $this->actingAs($this->passenger());

        $this->assertSame(0, Summary::query()->count(), 'a passenger must read no takings');
        $this->assertSame(0.0, (float) Summary::query()->sum('mpesa_amount'));
    }

    #[Test]
    public function a_passenger_can_still_find_a_bus_to_board(): void
    {
        // The other half. A fail-closed scope that also closed the book-a-ride
        // catalogue would be a working tenant boundary on a broken product.
        $a = $this->makeWorld();
        $b = $this->makeWorld();

        $this->actingAs($this->passenger());

        $saccos = Sacco::query()->pluck('id')->all();
        $this->assertContains($a['sacco']->id, $saccos);
        $this->assertContains($b['sacco']->id, $saccos, 'a passenger browses every SACCO, not one');

        $vehicles = Vehicle::query()->pluck('id')->all();
        $this->assertContains($a['vehicle']->id, $vehicles);
        $this->assertContains($b['vehicle']->id, $vehicles);
    }

    #[Test]
    public function closed_models_are_filtered_to_nothing_for_a_tenantless_caller(): void
    {
        // SQL shape rather than rows: building a valid fixture for twenty tables
        // would mostly test the fixtures, and the property is identical for all
        // of them. The row-level proof is the takings test above.
        $this->actingAs($this->passenger());

        foreach (self::CLOSED as $class) {
            $this->assertStringContainsString(
                '1 = 0',
                $class::query()->toSql(),
                $class.' must be denied to a caller with no SACCO'
            );
        }
    }

    #[Test]
    public function exactly_the_book_a_ride_catalogue_opts_in_and_nothing_else(): void
    {
        // Walks every model in the app rather than trusting the list above, so a
        // NEW model that opts in fails here even if nobody updates this file.
        $opted = [];

        foreach (glob(app_path('Models/*.php')) as $file) {
            $class = 'App\\Models\\'.basename($file, '.php');

            if (! class_exists($class) || ! is_subclass_of($class, Model::class)) {
                continue;
            }

            $reflection = new ReflectionClass($class);

            if ($reflection->isAbstract()) {
                continue;
            }

            if (! in_array(BelongsToSacco::class, $this->traitsOf($reflection), true)) {
                continue;
            }

            if ((new $class)->allowsCrossTenantBrowsing()) {
                $opted[] = $class;
            }
        }

        sort($opted);
        $expected = self::OPEN;
        sort($expected);

        $this->assertSame(
            $expected,
            $opted,
            "The cross-tenant browsing list changed.\n".
            "Every model on it is readable by ANY logged-in passenger, across every SACCO.\n".
            'Add one only if a passenger cannot book a ride without it, and change this test deliberately.'
        );
    }

    #[Test]
    public function a_sacco_admin_is_still_confined_to_their_own_sacco(): void
    {
        // The opt-in exempts the TENANTLESS caller only. A user who HAS a SACCO
        // must not reach another one's rows through the same door — otherwise
        // this fix would have opened a hole while closing one.
        $mine = $this->makeWorld();
        $theirs = $this->makeWorld();

        $admin = $this->makeUser([], $mine['sacco']);
        $admin->type = UserType::Admin;
        $admin->save();

        $this->actingAs($admin->fresh());

        $vehicles = Vehicle::query()->pluck('id')->all();
        $this->assertContains($mine['vehicle']->id, $vehicles);
        $this->assertNotContains(
            $theirs['vehicle']->id,
            $vehicles,
            'cross-tenant browsing must not leak to a caller who has a tenant'
        );

        $this->assertNotContains($theirs['sacco']->id, Sacco::query()->pluck('id')->all());
    }

    #[Test]
    public function a_super_admin_is_unaffected(): void
    {
        $a = $this->makeWorld();
        $b = $this->makeWorld();

        $this->takings($b['vehicle']->id, 500);

        $super = $this->makeUser([], null);
        $super->type = UserType::Superadmin;
        $super->save();

        $this->actingAs($super->fresh());

        $this->assertSame(1, Summary::query()->count(), 'a super admin still sees the platform');
        $this->assertContains($a['sacco']->id, Sacco::query()->pluck('id')->all());
    }

    #[Test]
    public function an_unauthenticated_caller_is_untouched_so_webhooks_keep_working(): void
    {
        // M-Pesa confirmation callbacks arrive with no session. If the scope
        // applied to them, payments would stop being recorded.
        // No actingAs anywhere in this test: the request is a guest, exactly as
        // a Safaricom callback arrives.
        $world = $this->makeWorld();

        $this->assertContains($world['vehicle']->id, Vehicle::query()->pluck('id')->all());
        $this->assertStringNotContainsString('1 = 0', Summary::query()->toSql());
    }

    /**
     * Trait names on a class and all its parents, flattened.
     *
     * @return array<int, string>
     */
    private function traitsOf(ReflectionClass $reflection): array
    {
        $traits = [];

        for ($c = $reflection; $c !== false; $c = $c->getParentClass()) {
            foreach ($c->getTraitNames() as $trait) {
                $traits[] = $trait;
            }
        }

        return $traits;
    }
}
