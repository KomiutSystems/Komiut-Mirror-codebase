<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use App\Events\PaymentRecorded;
use App\Models\Transaction;
use App\Models\Vehicle;
use App\Models\VehicleUser;
use App\Services\Mpesa\C2bPaymentRecorder;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Queues\QueueTestCase;

/**
 * Drivers see a fare the moment it lands, not on the next poll.
 *
 * Reverb already carries the live map in production, so this rides proven
 * infrastructure. What these tests pin is the part that is easy to get wrong:
 * WHO hears it, and WHEN it must stay quiet.
 *
 * Polling is deliberately NOT replaced. Crews work on mobile data in a moving
 * matatu, where a socket drops silently and a driver would never know a payment
 * had been missed. The push is for immediacy; the poll stays the correction.
 *
 * TWO HARNESS TRAPS COST FOUR RED CI RUNS ON THE FIRST ATTEMPT, and both are
 * defended against below rather than rediscovered:
 *
 *  1. phpunit.xml pins BROADCAST_CONNECTION=null, and NullBroadcaster::auth()
 *     is an empty method body — it authorises every channel for every caller.
 *     Channel tests under that driver assert nothing at all.
 *  2. Switching the driver in setUp() is not enough on its own.
 *     Broadcast::channel() registers onto the broadcaster that existed when
 *     routes/channels.php ran at boot — the null one — so a new driver starts
 *     with NO channels and refuses everything, authorised or not. The channel
 *     file has to be re-run against the driver actually under test.
 */
final class PaymentBroadcastTest extends QueueTestCase
{
    /** @var list<string> */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();
        Context::add('brand', 'testing');

        config([
            'broadcasting.default' => 'pusher',
            'broadcasting.connections.pusher' => [
                'driver' => 'pusher',
                'key' => 'test-key',
                'secret' => 'test-secret',
                'app_id' => 'test-app-id',
                'options' => ['cluster' => 'eu', 'useTLS' => true],
            ],
        ]);

        // Re-register the channels onto the driver above — see trap 2.
        require base_path('routes/channels.php');

        // announce() catches its own failures so a websocket can never cost us a
        // payment. That catch also hid the reason through four red runs, so the
        // log is captured here and asserted empty: a swallowed error now shows
        // up in the failure message instead of as a silent non-dispatch.
        $this->logged = [];
        Log::listen(function ($message): void {
            $this->logged[] = $message->level.': '.$message->message.' '.json_encode($message->context);
        });
    }

    private function assertNothingWasSwallowed(): void
    {
        $this->assertSame([], $this->logged, 'announce() swallowed: '.implode(' | ', $this->logged));
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
        $vehicle = $world['vehicle'];
        $vehicle->merchant_short_code = '5202020';
        $vehicle->save();

        return $vehicle;
    }

    private function record(array $fields): void
    {
        $this->recorder()->record(
            $fields,
            fn (string $sc) => Vehicle::withoutGlobalScopes()->where('merchant_short_code', $sc)->first()
        );
    }

    #[Test]
    public function the_fixture_actually_links_the_payment_to_the_bus(): void
    {
        // Asserted separately and FIRST on purpose: every "must stay quiet" test
        // below passes trivially if the vehicle never resolves, so without this a
        // broken fixture would look like a working guard.
        $bus = $this->busOnShortcode();

        $this->record($this->fields(['TransID' => 'LINK1']));

        $txn = Transaction::withoutGlobalScopes()->latest('id')->first();

        $this->assertNotNull($txn);
        $this->assertSame($bus->id, $txn->vehicle_id);
        $this->assertNotNull($txn->trans_date);
    }

    #[Test]
    public function a_fare_is_pushed_to_the_bus_that_earned_it(): void
    {
        Event::fake([PaymentRecorded::class]);
        $bus = $this->busOnShortcode();

        $this->record($this->fields(['TransID' => 'LIVE1', 'TransAmount' => 150]));

        $this->assertNothingWasSwallowed();

        Event::assertDispatched(PaymentRecorded::class, function (PaymentRecorded $e) use ($bus) {
            $channels = $e->broadcastOn();

            return $e->transaction->vehicle_id === $bus->id
                && ($channels[0] ?? null) instanceof PrivateChannel
                && $channels[0]->name === 'private-vehicle.'.$bus->id;
        });
    }

    #[Test]
    public function the_pushed_payload_matches_what_the_polled_list_returns(): void
    {
        // The app must render a pushed payment and a polled one through the same
        // code, so the shapes cannot drift.
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
        // is 46,819 rows.
        Event::fake([PaymentRecorded::class]);
        $this->busOnShortcode();

        $this->record($this->fields([
            'TransID' => 'OLD1',
            'TransTime' => now()->subDays(20)->format('YmdHis'),
        ]));

        // Recorded, just not announced — the money still lands.
        $this->assertNotNull(Transaction::withoutGlobalScopes()->latest('id')->first()?->vehicle_id);
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

        $this->assertNothingWasSwallowed();
        Event::assertDispatchedTimes(PaymentRecorded::class, 1);
    }

    #[Test]
    public function an_unattributed_payment_goes_to_no_channel(): void
    {
        // Money we cannot place on a bus is still recorded and still alarmed
        // through reportUnmatchedPayment — it simply has no crew to tell.
        Event::fake([PaymentRecorded::class]);
        $this->busOnShortcode();

        $this->record($this->fields(['TransID' => 'ORPHAN1', 'BusinessShortCode' => '9999999']));

        $this->assertNull(Transaction::withoutGlobalScopes()->latest('id')->first()?->vehicle_id);
        Event::assertNotDispatched(PaymentRecorded::class);
    }

    #[Test]
    public function only_the_crew_currently_on_the_bus_may_listen(): void
    {
        // Crews rotate between matatus and the money belongs to the till, not the
        // person, so a driver who came off this bus must stop hearing its takings.
        // Driven through the REAL /broadcasting/auth endpoint: a test that
        // re-implements the callback passes happily while the registered channel
        // says something else.
        $bus = $this->busOnShortcode();
        $other = $this->makeWorld()['vehicle'];

        $onIt = $this->makeUser([], null);
        VehicleUser::create(['user_id' => $onIt->id, 'vehicle_id' => $bus->id, 'status' => true]);

        $offIt = $this->makeUser([], null);
        VehicleUser::create(['user_id' => $offIt->id, 'vehicle_id' => $other->id, 'status' => true]);

        Sanctum::actingAs($onIt);
        $this->postJson('/broadcasting/auth', [
            'channel_name' => 'private-vehicle.'.$bus->id,
            'socket_id' => '1234.5678',
        ])->assertOk();

        Sanctum::actingAs($offIt);
        $this->postJson('/broadcasting/auth', [
            'channel_name' => 'private-vehicle.'.$bus->id,
            'socket_id' => '1234.5678',
        ])->assertForbidden();
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
        $this->postJson('/broadcasting/auth', [
            'channel_name' => 'private-vehicle.'.$bus->id,
            'socket_id' => '1234.5678',
        ])->assertOk();

        $assignment->forceFill(['end_date' => now()->subDay()])->save();

        $this->postJson('/broadcasting/auth', [
            'channel_name' => 'private-vehicle.'.$bus->id,
            'socket_id' => '1234.5678',
        ])->assertForbidden();
    }
}
