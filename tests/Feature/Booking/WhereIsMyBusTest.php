<?php

declare(strict_types=1);

namespace Tests\Feature\Booking;

use App\Enums\BookingCancellationReason;
use App\Enums\PaymentMethod;
use App\Enums\UserType;
use App\Models\Booking;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyProgram;
use App\Models\Queue;
use App\Models\User;
use App\Models\VehicleLocation;
use App\Models\VehicleUser;
use App\Services\Location\VehicleLocationService;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Queues\QueueTestCase;

/**
 * "My bookings" says where each booking is in its life, and shows a map
 * button on the ones with a bus to watch.
 *
 * Seven states (App\Services\Booking\BookingState), one flag -- `trackable` --
 * and one endpoint the map opens on: bookings/passengers/track/{id}, which
 * hands the app the bus's last position with an honest `live` verdict, the
 * pickup and drop-off, the route's stops for the line, and the channel to
 * listen on for every move after that.
 */
final class WhereIsMyBusTest extends QueueTestCase
{
    private const LIST = '/api/v1/auth/bookings/passengers?range=all';

    private function scene(string $queueStatus = 'Pending'): array
    {
        $world = $this->makeWorld();
        $world['stages'][0]->update(['latitude' => -1.2833, 'longitude' => 36.8167]);
        $world['stages'][1]->update(['latitude' => -1.0333, 'longitude' => 37.0693]);
        LoyaltyProgram::withoutGlobalScopes()->create([
            'sacco_id' => $world['sacco']->id, 'is_active' => true,
            'divisor' => 100, 'redemption_threshold' => 5, 'point_value' => 30,
        ]);
        $queue = $this->makeQueue(
            $world['vehicle'], $world['terminus'], $world['route'],
            $this->makeQueueStatus($queueStatus.' '.$this->nextSequence(), $queueStatus), $world['owner'],
        );

        return ['world' => $world, 'queue' => $queue];
    }

    private function passenger(array $scene, float $points = 50): User
    {
        $u = $this->makeUser();
        $u->forceFill(['type' => UserType::Passenger])->save();
        LoyaltyAccount::withoutGlobalScopes()->create([
            'user_id' => $u->id, 'sacco_id' => $scene['world']['sacco']->id, 'balance' => $points,
        ]);

        return $u;
    }

    private function booking(array $scene, User $u, ?Queue $queue = null): Booking
    {
        $b = $this->makeBooking($queue ?? $scene['queue'], $u, $scene['world']['from'], $scene['world']['to']);
        $b->forceFill(['amount' => 150])->save();

        return $b->fresh();
    }

    private function crew(array $scene): User
    {
        $driver = $this->makeUser(['Edit Queues'], $scene['world']['sacco']);
        $driver->forceFill(['type' => UserType::Driver])->save();
        VehicleUser::create([
            'user_id' => $driver->id, 'vehicle_id' => $scene['world']['vehicle']->id,
            'sacco_id' => $scene['world']['sacco']->id, 'status' => true, 'start_date' => now(),
        ]);

        return $driver;
    }

    #[Test]
    public function every_state_a_passenger_can_be_in_has_a_name_and_only_the_waiting_ones_are_trackable(): void
    {
        $scene = $this->scene('Active');
        $this->makeQueueStatus('Completed '.$this->nextSequence(), 'Completed');
        $tom = $this->passenger($scene);

        $reserved = $this->booking($scene, $tom);

        $confirmed = $this->booking($scene, $tom);
        Sanctum::actingAs($tom);
        $this->postJson('/api/v1/auth/book_a_ride/loyalty/redeem', ['booking_id' => $confirmed->id])->assertOk();

        $boarded = $this->booking($scene, $tom);
        $boarded->update(['paid' => true, 'payment_method' => PaymentMethod::Mpesa]);
        $notBoarded = $this->booking($scene, $tom);
        $notBoarded->update(['paid' => true, 'payment_method' => PaymentMethod::Mpesa]);

        $crew = $this->crew($scene);
        Sanctum::actingAs($crew);
        $this->postJson('/api/auth/driver/bookings/'.$boarded->id.'/mark', ['action' => 'board'])->assertOk();
        $this->postJson('/api/auth/driver/bookings/'.$notBoarded->id.'/mark', ['action' => 'no_show'])->assertOk();

        $cancelled = $this->booking($scene, $tom);
        Sanctum::actingAs($tom);
        $this->postJson('/api/v1/auth/bookings/passengers/cancel/'.$cancelled->id)->assertOk();

        $expired = $this->booking($scene, $tom);
        $expired->forceFill(['created_at' => now()->subMinutes(30)])->save();
        $this->artisan('bookings:release-expired')->assertSuccessful();

        // A completed trip: boarded, and the queue is over.
        $done = $this->scene('Completed');
        $completed = $this->booking($done, $tom, $done['queue']);
        $completed->update(['paid' => true, 'boarded' => true]);

        Sanctum::actingAs($tom);
        $rows = collect($this->getJson(self::LIST)->assertOk()->json('bookings'))->keyBy('id');

        $expect = [
            $reserved->id => ['reserved', true],
            $confirmed->id => ['confirmed', true],
            $boarded->id => ['boarded', false],
            $notBoarded->id => ['not_boarded', false],
            $cancelled->id => ['cancelled', false],
            $expired->id => ['expired', false],
            $completed->id => ['completed', false],
        ];
        foreach ($expect as $id => [$state, $trackable]) {
            $this->assertSame($state, $rows[$id]['state'], "booking $id");
            $this->assertSame($trackable, $rows[$id]['trackable'], "booking $id ($state) trackable");
        }

        // The refund is on the not-boarded row, in the words the screen needs.
        $this->assertSame('mpesa', $rows[$notBoarded->id]['paid_with']);
        $this->assertSame('no_show', $rows[$notBoarded->id]['cancellation_reason']);
        $this->assertNotNull($rows[$notBoarded->id]['cancelled_at']);
        $this->assertSame('expired', $rows[$expired->id]['cancellation_reason']);
        $this->assertSame('cancelled', $rows[$cancelled->id]['cancellation_reason']);
    }

    #[Test]
    public function a_trip_that_ended_makes_a_waiting_booking_untrackable_and_the_sweep_names_it(): void
    {
        // The crew ends the trip with `unmarked: no_show`: the confirmed
        // passenger becomes not_boarded, refunded, and there is no bus to show.
        $scene = $this->scene('Active');
        $this->makeQueueStatus('Completed '.$this->nextSequence(), 'Completed');
        $tom = $this->passenger($scene);
        $booking = $this->booking($scene, $tom);
        Sanctum::actingAs($tom);
        $this->postJson('/api/v1/auth/book_a_ride/loyalty/redeem', ['booking_id' => $booking->id])->assertOk();

        Sanctum::actingAs($this->crew($scene));
        $this->postJson('/api/v1/auth/driver/trip/end', ['unmarked' => 'no_show'])->assertOk();

        Sanctum::actingAs($tom);
        $row = collect($this->getJson(self::LIST)->assertOk()->json('bookings'))->firstWhere('id', $booking->id);
        $this->assertSame('not_boarded', $row['state']);
        $this->assertFalse($row['trackable']);
        $this->assertSame(BookingCancellationReason::NoShow->value, $row['cancellation_reason']);
        $this->assertSame('points', $row['paid_with']);
    }

    #[Test]
    public function the_map_screen_gets_the_bus_the_stops_and_the_channel_in_one_call(): void
    {
        $scene = $this->scene('Active');
        $tom = $this->passenger($scene);
        $booking = $this->booking($scene, $tom);
        VehicleLocation::create([
            'vehicle_id' => $scene['world']['vehicle']->id, 'route_id' => $scene['world']['route']->id,
            'queue_id' => $scene['queue']->id, 'latitude' => -1.20, 'longitude' => 36.95, 'heading' => 45,
            'broadcasting' => true, 'recorded_at' => now()->subSeconds(10),
        ]);

        Sanctum::actingAs($tom);
        $r = $this->getJson('/api/v1/auth/bookings/passengers/track/'.$booking->id)->assertOk();

        $r->assertJsonPath('booking_id', $booking->id)
            ->assertJsonPath('state', 'reserved')
            ->assertJsonPath('trackable', true)
            ->assertJsonPath('vehicle.plate', $scene['world']['vehicle']->plate)
            ->assertJsonPath('trip.queue_id', $scene['queue']->id)
            ->assertJsonPath('trip.channel', 'trip.'.$scene['queue']->id)
            ->assertJsonPath('trip.event', 'vehicle.moved')
            ->assertJsonPath('bus.latitude', -1.2)
            ->assertJsonPath('bus.longitude', 36.95)
            ->assertJsonPath('bus.heading', 45)
            ->assertJsonPath('bus.live', true)
            ->assertJsonPath('pickup.place_id', $scene['world']['from']->id)
            ->assertJsonPath('pickup.latitude', -1.2833)
            ->assertJsonPath('dropoff.place_id', $scene['world']['to']->id)
            ->assertJsonPath('dropoff.longitude', 37.0693)
            ->assertJsonCount(2, 'route.stops');

        $this->assertLessThanOrEqual(15, $r->json('bus.age_seconds'));
    }

    #[Test]
    public function a_stale_position_is_handed_over_but_not_called_live(): void
    {
        // The driver's phone went quiet. The last position is still worth
        // drawing -- greyed, with "last seen" -- but the app must not animate a
        // confident dot from it. `live` is that decision, made server-side.
        $scene = $this->scene('Active');
        $tom = $this->passenger($scene);
        $booking = $this->booking($scene, $tom);
        VehicleLocation::create([
            'vehicle_id' => $scene['world']['vehicle']->id, 'route_id' => $scene['world']['route']->id,
            'queue_id' => $scene['queue']->id, 'latitude' => -1.20, 'longitude' => 36.95,
            'broadcasting' => true, 'recorded_at' => now()->subSeconds(VehicleLocationService::FRESH_SECONDS + 60),
        ]);

        Sanctum::actingAs($tom);
        $this->getJson('/api/v1/auth/bookings/passengers/track/'.$booking->id)
            ->assertOk()
            ->assertJsonPath('bus.live', false)
            ->assertJsonPath('bus.broadcasting', true)
            ->assertJsonPath('bus.latitude', -1.2);
    }

    #[Test]
    public function a_bus_that_has_never_broadcast_is_null_not_a_dot_at_sea(): void
    {
        $scene = $this->scene('Pending');
        $tom = $this->passenger($scene);
        $booking = $this->booking($scene, $tom);

        Sanctum::actingAs($tom);
        $this->getJson('/api/v1/auth/bookings/passengers/track/'.$booking->id)
            ->assertOk()
            ->assertJsonPath('bus', null)
            ->assertJsonPath('trackable', true);
    }

    #[Test]
    public function another_passengers_booking_cannot_be_tracked(): void
    {
        $scene = $this->scene('Active');
        $booking = $this->booking($scene, $this->passenger($scene));

        Sanctum::actingAs($this->passenger($scene));
        $this->getJson('/api/v1/auth/bookings/passengers/track/'.$booking->id)->assertStatus(403);
        $this->getJson('/api/v1/auth/bookings/passengers/track/999999')->assertStatus(404);
    }
}
