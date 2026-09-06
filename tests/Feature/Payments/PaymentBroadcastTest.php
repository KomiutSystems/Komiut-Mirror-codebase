<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use App\Events\PaymentRecorded;
use App\Models\Vehicle;
use App\Models\VehicleUser;
use App\Services\Mpesa\C2bPaymentRecorder;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Queues\QueueTestCase;

/**
 * Drivers see a fare the moment it lands, not on the next poll.
 *
 * The driver app learned it had been paid by re-fetching driver/home and
 * diffing the list, so a payment could sit unseen for a whole poll interval.
 * Reverb was already in production carrying the live map, so this rides
 * infrastructure that is already proven — what these tests pin is the part that
 * is easy to get wrong: WHO hears it, and WHEN it must stay quiet.
 *
 * Polling is deliberately NOT removed. Crews work on mobile data in a moving
 * matatu, where a socket drops silently and a driver would never know a payment
 * had been missed. The push is for immediacy; the poll remains the truth.
 */
final class PaymentBroadcastTest extends QueueTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Context::add('brand', 'testing');
    }

    private function recorder(): C2bPaymentRecorder
    {
        return app(C2bPaymentRecorder::class);
    }

    /** A confirmation as Safaricom sends it, timed relative to now. */
    private function fields(array $o = []): array
    {
        return array_merge([
            'TransID' => 'TX'.$this->nextSequence(),
            'TransAmount' => 200,
            'TransTime' => now()->format('YmdHis'),
            'BusinessShortCode' => '5202020',
            'MSISDN' => '254700111222',
            'FirstName' => 'Joyce',
            'BillRefNumber' => 'KDA123X',
        ], $o);
    }

    private function busOnShortcode(): Vehicle
    {
        $world = $this->makeWorld();
        $world['vehicle']->merchant_short_code = '5202020';
        $world['vehicle']->save();

        return $world['vehicle']->fresh();
    }

    private function record(array $fields): void
    {
        $this->recorder()->record(
            $fields,
            fn (string $sc) => Vehicle::withoutGlobalScopes()->where('merchant_short_code', $sc)->first()
        );
    }

    #[Test]
    public function a_fare_is_pushed_to_the_bus_that_earned_it(): void
    {
        Event::fake([PaymentRecorded::class]);
        $bus = $this->busOnShortcode();

        $this->record($this->fields(['TransID' => 'LIVE1', 'TransAmount' => 150]));

        Event::assertDispatched(PaymentRecorded::class, function (PaymentRecorded $e) use ($bus) {
            $channels = $e->broadcastOn();

            return $e->transaction->vehicle_id === $bus->id
                && $channels[0] instanceof PrivateChannel
                && $channels[0]->name === 'private-vehicle.'.$bus->id;
        });
    }

    #[Test]
    public function the_pushed_payload_matches_what_the_polled_list_returns(): void
    {
        // The app must render a pushed payment and a polled one through the
        // same code, so the shapes cannot drift.
        Event::fake([PaymentRecorded::class]);
        $this->busOnShortcode();

        $this->record($this->fields(['TransID' => 'SHAPE1', 'TransAmount' => 70, 'FirstName' => 'Joyce']));

        Event::assertDispatched(PaymentRecorded::class, function (PaymentRecorded $e) {
            $p = $e->broadcastWith();

            return $p['amount'] === 70.0
                && $p['method'] === 'mpesa'
                && $p['reference'] === 'SHAPE1'
                && $p['payer'] === 'Joyce'
                && $p['at'] !== null;   // a realtime feed of undated payments is worthless
        });
    }

    #[Test]
    public function a_backfilled_payment_never_pings_a_phone(): void
    {
        // THE ONE THAT WOULD HAVE HURT. This recorder is also the save chain for
        // payments:backfill-from-legacy, and the outstanding NCBA backfill alone
        // is 46,819 rows. Broadcasting those would fire tens of thousands of
        // pushes about fares collected weeks ago.
        Event::fake([PaymentRecorded::class]);
        $this->busOnShortcode();

        $this->record($this->fields([
            'TransID' => 'OLD1',
            'TransTime' => now()->subDays(20)->format('YmdHis'),
        ]));

        Event::assertNotDispatched(PaymentRecorded::class);
    }

    #[Test]
    public function a_replayed_confirmation_does_not_notify_twice(): void
    {
        // Safaricom retries anything slow or non-2xx. The crew must be told once.
        Event::fake([PaymentRecorded::class]);
        $this->busOnShortcode();

        $fields = $this->fields(['TransID' => 'RETRY1']);
        $this->record($fields);
        $this->record($fields);

        Event::assertDispatchedTimes(PaymentRecorded::class, 1);
    }

    #[Test]
    public function an_unattributed_payment_goes_to_no_channel(): void
    {
        // Money we cannot place on a bus is still recorded and still alarmed
        // through reportUnmatchedPayment — it simply has no crew to tell.
        Event::fake([PaymentRecorded::class]);

        $this->record($this->fields(['TransID' => 'ORPHAN1', 'BusinessShortCode' => '9999999']));

        Event::assertNotDispatched(PaymentRecorded::class);
    }

    #[Test]
    public function only_the_crew_currently_on_the_bus_may_listen(): void
    {
        // Crews rotate between matatus and the money belongs to the till, not
        // the person: a driver who came off this bus must stop hearing its
        // takings.
        //
        // This drives the REAL /broadcasting/auth endpoint rather than a copy of
        // the rule. A test that re-implements the callback passes happily while
        // the registered channel says something else, which is precisely how the
        // bank scope tests stayed green against a role that lacked the
        // permission.
        $bus = $this->busOnShortcode();
        $other = $this->makeWorld()['vehicle'];

        $onIt = $this->makeUser([], null);
        VehicleUser::create(['user_id' => $onIt->id, 'vehicle_id' => $bus->id, 'status' => true]);

        $offIt = $this->makeUser([], null);
        VehicleUser::create(['user_id' => $offIt->id, 'vehicle_id' => $other->id, 'status' => true]);

        Sanctum::actingAs($onIt);
        $this->postJson('/broadcasting/auth', ['channel_name' => 'private-vehicle.'.$bus->id])
            ->assertOk();

        Sanctum::actingAs($offIt);
        $this->postJson('/broadcasting/auth', ['channel_name' => 'private-vehicle.'.$bus->id])
            ->assertForbidden();
    }

    #[Test]
    public function a_closed_assignment_stops_hearing_the_bus(): void
    {
        $bus = $this->busOnShortcode();

        $former = $this->makeUser([], null);
        $assignment = VehicleUser::create([
            'user_id' => $former->id,
            'vehicle_id' => $bus->id,
            'status' => true,
        ]);

        Sanctum::actingAs($former);
        $this->postJson('/broadcasting/auth', ['channel_name' => 'private-vehicle.'.$bus->id])
            ->assertOk();

        $assignment->forceFill(['end_date' => now()->subDay()])->save();

        $this->postJson('/broadcasting/auth', ['channel_name' => 'private-vehicle.'.$bus->id])
            ->assertForbidden();
    }

    #[Test]
    public function an_unauthenticated_listener_is_refused(): void
    {
        $bus = $this->busOnShortcode();

        $this->postJson('/broadcasting/auth', ['channel_name' => 'private-vehicle.'.$bus->id])
            ->assertUnauthorized();
    }
}
