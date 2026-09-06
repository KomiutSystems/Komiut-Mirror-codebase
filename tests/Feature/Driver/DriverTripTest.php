<?php

declare(strict_types=1);

namespace Tests\Feature\Driver;

use App\Enums\UserType;
use App\Models\Cash;
use App\Models\Queue;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleUser;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Queues\QueueTestCase;

/**
 * The write half of a driver's shift: read the trip, end it, take a cash fare,
 * board or no-show a passenger.
 *
 * What these pin down is that NOTHING here accepts a vehicle or queue id. Every
 * target is resolved from the caller's own open assignment, so the tests that
 * matter most are the cross-vehicle ones: the dashboard's equivalents take an
 * id straight from the request with no ownership check, which is the hole this
 * controller exists to close.
 */
final class DriverTripTest extends QueueTestCase
{
    /**
     * A driver crewed onto a vehicle that is loading at a terminus.
     *
     * @return array{driver: User, vehicle: Vehicle, queue: Queue, world: array<string,mixed>}
     */
    private function onShift(): array
    {
        $world = $this->makeWorld();
        $queue = $this->makeQueue(
            $world['vehicle'], $world['terminus'], $world['route'],
            $this->makeQueueStatus('Pending '.$this->nextSequence(), 'Pending'),
            $world['owner'],
            'QN-'.$this->nextSequence(),
        );

        $driver = $this->crew($world);

        return ['driver' => $driver, 'vehicle' => $world['vehicle'], 'queue' => $queue, 'world' => $world];
    }

    /**
     * The same shift, but the matatu has already pulled out of the stage.
     *
     * Ending a trip now requires having departed, so most of these tests need a
     * bus that is actually on the road rather than one still loading.
     */
    private function departed(): array
    {
        $shift = $this->onShift();

        $shift['queue']->forceFill([
            'queue_status_id' => $this->makeQueueStatus('Active '.$this->nextSequence(), 'Active')->id,
            'departed_at' => now(),
        ])->save();
        $shift['queue'] = $shift['queue']->fresh();

        return $shift;
    }

    /** @param array<string,mixed> $world */
    private function crew(array $world): User
    {
        $driver = $this->makeUser([], $world['sacco']);
        $driver->forceFill(['type' => UserType::Driver])->save();

        VehicleUser::create([
            'user_id' => $driver->id,
            'vehicle_id' => $world['vehicle']->id,
            'sacco_id' => $world['sacco']->id,
            'status' => true,
            'start_date' => now(),
        ]);

        return $driver;
    }

    #[Test]
    public function it_returns_the_current_trip(): void
    {
        $shift = $this->onShift();
        Sanctum::actingAs($shift['driver']);

        $this->getJson('/api/v1/auth/driver/trip')
            ->assertOk()
            ->assertJsonPath('trip.queue_id', $shift['queue']->id)
            ->assertJsonStructure(['trip' => [
                'queue_id', 'queue_number', 'status', 'route', 'from', 'to',
                'terminus', 'fare', 'started_at', 'ended_at',
            ]]);
    }

    #[Test]
    public function being_between_trips_is_null_and_not_an_error(): void
    {
        // The app renders a Join Queue button off this. A 404 would make an
        // ordinary state -- idle at the stage -- look like a failure.
        $driver = $this->crew($this->makeWorld());

        Sanctum::actingAs($driver);

        $this->getJson('/api/v1/auth/driver/trip')->assertOk()->assertJsonPath('trip', null);
    }

    #[Test]
    public function ending_a_trip_completes_it_and_stamps_the_end_time(): void
    {
        // Departs first, because that is now the only way to reach an end. This
        // test used to end a queue still sitting at the stage and expect a
        // completed trip, which is exactly the phantom-trip case the guard
        // closes.
        $shift = $this->departed();
        $completed = $this->makeQueueStatus('Completed '.$this->nextSequence(), 'Completed');
        Sanctum::actingAs($shift['driver']);

        $this->postJson('/api/v1/auth/driver/trip/end')->assertOk();

        $queue = $shift['queue']->fresh();
        $this->assertSame($completed->id, (int) $queue->queue_status_id);
        // The dashboard's complete/queue never sets this, so a finished trip had
        // no duration at all.
        $this->assertNotNull($queue->end_time);
    }

    #[Test]
    public function a_driver_cannot_end_another_vehicles_trip(): void
    {
        // There is no id to pass, which IS the defence: an off-shift driver has
        // no queue to resolve, so there is nothing for them to close.
        $shift = $this->onShift();
        $this->makeQueueStatus('Completed '.$this->nextSequence(), 'Completed');

        $intruder = $this->crew($this->makeWorld());

        Sanctum::actingAs($intruder);
        $this->postJson('/api/v1/auth/driver/trip/end')->assertStatus(404);

        $this->assertNull($shift['queue']->fresh()->end_time);
    }

    #[Test]
    public function a_cash_fare_reaches_the_takings(): void
    {
        // Flipping `paid` alone would leave the money invisible: takings and the
        // SACCO summaries both read `transactions`, so the row has to be there.
        $shift = $this->onShift();
        $booking = $this->makeBooking($shift['queue'], $this->makeUser([], $shift['world']['sacco']),
            $shift['world']['from'], $shift['world']['to'], 'Wanjiku');

        Sanctum::actingAs($shift['driver']);

        // 200 is the fare makeBooking sets. It used to read 150 here — below the
        // fare — which the endpoint no longer accepts; the amount was incidental
        // to what this test is about, which is that the money reaches the tables.
        $this->postJson("/api/v1/auth/driver/bookings/{$booking->id}/cash", ['amount' => 200])
            ->assertStatus(201)
            ->assertJsonPath('booking.status', 'confirmed');

        $this->assertDatabaseHas('cashes', [
            'trans_id' => 'CASH-'.$booking->id,
            'vehicle_id' => $shift['vehicle']->id,
            'total_amount' => 200,
        ]);
        $this->assertDatabaseHas('transactions', ['vehicle_id' => $shift['vehicle']->id, 'amount' => 200]);
        $this->assertTrue((bool) $booking->fresh()->paid);
    }

    #[Test]
    public function a_driver_cannot_bank_less_than_the_booked_fare(): void
    {
        // The leak this closes: mark a 200/= booking paid with 1/=, pocket the
        // rest, and the system's own records back the driver up. The booking
        // must stay unpaid and nothing may reach the takings.
        $shift = $this->onShift();
        $booking = $this->makeBooking($shift['queue'], $this->makeUser([], $shift['world']['sacco']),
            $shift['world']['from'], $shift['world']['to'], 'Achieng');

        Sanctum::actingAs($shift['driver']);

        $this->postJson("/api/v1/auth/driver/bookings/{$booking->id}/cash", ['amount' => 1])
            ->assertStatus(422)
            ->assertJsonPath('errors.amount.0', 'A cash fare cannot be less than the booked fare of 200.00.');

        $this->assertDatabaseMissing('cashes', ['trans_id' => 'CASH-'.$booking->id]);
        $this->assertSame(0, Transaction::withoutGlobalScopes()->where('vehicle_id', $shift['vehicle']->id)->count());
        $this->assertFalse((bool) $booking->fresh()->paid);
    }

    #[Test]
    public function a_driver_may_bank_more_than_the_booked_fare(): void
    {
        // Luggage, or a passenger rounding up. Above the fare is legitimate and
        // is recorded as given, not clamped back down to the booked amount.
        $shift = $this->onShift();
        $booking = $this->makeBooking($shift['queue'], $this->makeUser([], $shift['world']['sacco']),
            $shift['world']['from'], $shift['world']['to'], 'Kamau');

        Sanctum::actingAs($shift['driver']);

        $this->postJson("/api/v1/auth/driver/bookings/{$booking->id}/cash", ['amount' => 250])
            ->assertStatus(201);

        $this->assertDatabaseHas('transactions', ['vehicle_id' => $shift['vehicle']->id, 'amount' => 250]);
    }

    #[Test]
    public function an_omitted_amount_banks_the_booked_fare(): void
    {
        $shift = $this->onShift();
        $booking = $this->makeBooking($shift['queue'], $this->makeUser([], $shift['world']['sacco']),
            $shift['world']['from'], $shift['world']['to'], 'Njeri');

        Sanctum::actingAs($shift['driver']);

        $this->postJson("/api/v1/auth/driver/bookings/{$booking->id}/cash")->assertStatus(201);

        $this->assertDatabaseHas('transactions', ['vehicle_id' => $shift['vehicle']->id, 'amount' => 200]);
    }

    #[Test]
    public function a_non_numeric_amount_is_rejected(): void
    {
        // Without an explicit check this cast to 0.0 and fell through to the
        // "greater than zero" branch, which reported the wrong problem.
        $shift = $this->onShift();
        $booking = $this->makeBooking($shift['queue'], $this->makeUser([], $shift['world']['sacco']),
            $shift['world']['from'], $shift['world']['to'], 'Mutiso');

        Sanctum::actingAs($shift['driver']);

        $this->postJson("/api/v1/auth/driver/bookings/{$booking->id}/cash", ['amount' => 'abc'])
            ->assertStatus(400)
            ->assertJsonPath('errors.amount.0', 'A cash fare must be a number.');

        $this->assertFalse((bool) $booking->fresh()->paid);
    }

    #[Test]
    public function the_same_fare_cannot_be_banked_twice(): void
    {
        // Matatu connectivity being what it is, a conductor double-taps. The
        // second tap must not create a second cash row and a second transaction.
        $shift = $this->onShift();
        $booking = $this->makeBooking($shift['queue'], $this->makeUser([], $shift['world']['sacco']),
            $shift['world']['from'], $shift['world']['to'], 'Otieno');

        Sanctum::actingAs($shift['driver']);

        $this->postJson("/api/v1/auth/driver/bookings/{$booking->id}/cash", ['amount' => 200])->assertStatus(201);
        $this->postJson("/api/v1/auth/driver/bookings/{$booking->id}/cash", ['amount' => 200])->assertStatus(409);

        // Counted without the tenant scopes: the question here is what is in the
        // table, not what this caller is allowed to see.
        $this->assertSame(1, Cash::where('trans_id', 'CASH-'.$booking->id)->count());
        $this->assertSame(1, Transaction::withoutGlobalScopes()->where('vehicle_id', $shift['vehicle']->id)->count());
    }

    #[Test]
    public function a_driver_cannot_take_cash_on_another_vehicles_booking(): void
    {
        $shift = $this->onShift();
        $victim = $this->makeBooking($shift['queue'], $this->makeUser([], $shift['world']['sacco']),
            $shift['world']['from'], $shift['world']['to'], 'Achieng');

        $other = $this->onShift();
        Sanctum::actingAs($other['driver']);

        $this->postJson("/api/v1/auth/driver/bookings/{$victim->id}/cash", ['amount' => 150])
            ->assertStatus(404);

        $this->assertFalse((bool) $victim->fresh()->paid);
        $this->assertDatabaseMissing('cashes', ['trans_id' => 'CASH-'.$victim->id]);
    }

    #[Test]
    public function a_zero_fare_is_refused(): void
    {
        $shift = $this->onShift();
        $booking = $this->makeBooking($shift['queue'], $this->makeUser([], $shift['world']['sacco']),
            $shift['world']['from'], $shift['world']['to'], 'Kip');

        Sanctum::actingAs($shift['driver']);

        $this->postJson("/api/v1/auth/driver/bookings/{$booking->id}/cash", ['amount' => 0])->assertStatus(400);
        $this->assertDatabaseMissing('cashes', ['trans_id' => 'CASH-'.$booking->id]);
    }

    #[Test]
    public function boarding_and_no_show_move_the_booking(): void
    {
        $shift = $this->onShift();
        $passenger = $this->makeUser([], $shift['world']['sacco']);
        $boarding = $this->makeBooking($shift['queue'], $passenger, $shift['world']['from'], $shift['world']['to'], 'Mwangi');
        $absent = $this->makeBooking($shift['queue'], $passenger, $shift['world']['from'], $shift['world']['to'], 'Njeri');

        Sanctum::actingAs($shift['driver']);

        $this->postJson("/api/v1/auth/driver/bookings/{$boarding->id}/mark", ['action' => 'board'])
            ->assertOk()->assertJsonPath('booking.status', 'boarded');
        $this->assertTrue((bool) $boarding->fresh()->boarded);

        // A no-show reaches the same end state the unpaid sweep produces, but
        // now rather than in two minutes, so the seat can be resold.
        $this->postJson("/api/v1/auth/driver/bookings/{$absent->id}/mark", ['action' => 'no_show'])
            ->assertOk()->assertJsonPath('booking.status', 'failed');
        $this->assertFalse((bool) $absent->fresh()->status);
    }

    #[Test]
    public function an_unknown_mark_is_refused(): void
    {
        $shift = $this->onShift();
        $booking = $this->makeBooking($shift['queue'], $this->makeUser([], $shift['world']['sacco']),
            $shift['world']['from'], $shift['world']['to'], 'Barasa');

        Sanctum::actingAs($shift['driver']);

        $this->postJson("/api/v1/auth/driver/bookings/{$booking->id}/mark", ['action' => 'delete'])
            ->assertStatus(400);
        $this->assertTrue((bool) $booking->fresh()->status);
    }

    #[Test]
    public function an_unassigned_driver_is_refused_everywhere(): void
    {
        $driver = $this->makeUser([], $this->makeSacco());
        $driver->forceFill(['type' => UserType::Driver])->save();

        Sanctum::actingAs($driver);

        $this->getJson('/api/v1/auth/driver/trip')->assertStatus(403);
        $this->postJson('/api/v1/auth/driver/trip/end')->assertStatus(403);
        $this->postJson('/api/v1/auth/driver/bookings/1/cash', ['amount' => 100])->assertStatus(403);
        $this->postJson('/api/v1/auth/driver/bookings/1/mark', ['action' => 'board'])->assertStatus(403);
    }

    #[Test]
    public function these_endpoints_need_authentication(): void
    {
        $this->getJson('/api/v1/auth/driver/trip')->assertStatus(401);
        $this->postJson('/api/v1/auth/driver/trip/end')->assertStatus(401);
    }

    #[Test]
    public function a_trip_that_never_departed_cannot_be_ended(): void
    {
        // THE PHANTOM TRIP. currentQueue() resolves Active OR Pending, so a
        // driver who joined a queue and tapped end produced a Completed row for
        // a bus that never moved -- and completed queues are what the earnings
        // screen and the SACCO trip reports count. Joining by mistake has a
        // cancel; this path is for arriving.
        $shift = $this->onShift();   // still loading at the terminus
        Sanctum::actingAs($shift['driver']);

        $this->postJson('/api/v1/auth/driver/trip/end')
            ->assertStatus(409)
            ->assertJsonPath('error', 'You have not departed yet. Depart first, or cancel the queue.');

        $this->assertNull($shift['queue']->fresh()->end_time, 'a bus that never left has no arrival');
    }

    #[Test]
    public function departing_records_when_without_destroying_when_it_joined(): void
    {
        // start_time used to be overwritten on departure, so the moment a bus
        // pulled out there was no longer any record of when it had joined the
        // line -- and "how long did it wait at the stage" became unanswerable.
        $shift = $this->onShift();
        $joinedAt = $shift['queue']->fresh()->start_time;
        $this->makeQueueStatus('Active '.$this->nextSequence(), 'Active');

        // trips/start is gated on `permission:Edit Queues`, so the driver needs
        // it to depart at all.
        Permission::findOrCreate('Edit Queues', 'web');
        $shift['driver']->givePermissionTo('Edit Queues');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Sanctum::actingAs($shift['driver']->fresh());
        $this->postJson('/api/v1/auth/driver/trips/start')->assertOk();

        $queue = $shift['queue']->fresh();
        $this->assertNotNull($queue->departed_at, 'departure has to be recorded somewhere');
        $this->assertEquals($joinedAt, $queue->start_time, 'the join time must survive the departure');
    }

    #[Test]
    public function the_trip_payload_carries_real_timestamps(): void
    {
        // queues.start_time is not cast on the model, so the payload's
        // optional($queue->start_time)->toIso8601String() returned null for
        // every trip -- the same silent hole that left every driver payment
        // with "at": null.
        $shift = $this->departed();
        Sanctum::actingAs($shift['driver']);

        $trip = $this->getJson('/api/v1/auth/driver/trip')->assertOk()->json('trip');

        $this->assertNotNull($trip['started_at'], 'a trip with no start cannot be placed in a shift');
        $this->assertNotNull($trip['departed_at']);
    }
}
