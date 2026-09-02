<?php

declare(strict_types=1);

namespace Tests\Feature\Driver;

use App\Enums\UserType;
use App\Models\Booking;
use App\Models\Queue;
use App\Models\RouteStage;
use App\Models\User;
use App\Models\VehicleUser;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Queues\QueueTestCase;

/**
 * One gesture boards one passenger, and the list is in the order he meets them.
 *
 * The driver's screen is two tabs — "To collect" and "Aboard" — and a swipe
 * moves a row between them. That is a single call against a single booking,
 * which is the only boarding signal the system can actually trust: the
 * passenger is in front of the crew when it happens.
 */
final class BoardingBySwipeTest extends QueueTestCase
{
    /** @return array{0: User, 1: Queue, 2: array} */
    private function trip(): array
    {
        $world = $this->makeWorld();
        $driver = $this->makeUser([], $world['sacco']);
        $driver->forceFill(['type' => UserType::Driver])->save();

        VehicleUser::create([
            'user_id' => $driver->id,
            'vehicle_id' => $world['vehicle']->id,
            'sacco_id' => $world['sacco']->id,
            'status' => true,
            'start_date' => now()->subDay(),
        ]);

        $active = $this->makeQueueStatus('Active', 'Active');
        $queue = $this->makeQueue($world['vehicle'], $world['terminus'], $world['route'], $active, $world['owner']);

        return [$driver, $queue, $world];
    }

    private function paidBooking(Queue $queue, array $world, $from, string $name): Booking
    {
        $booking = $this->makeBooking($queue, $world['owner'], $from, $world['to'], $name);
        $booking->forceFill(['paid' => true])->save();

        return $booking;
    }

    #[Test]
    public function a_swipe_moves_one_passenger_from_to_collect_to_aboard(): void
    {
        [$driver, $queue, $world] = $this->trip();
        $booking = $this->paidBooking($queue, $world, $world['from'], 'Wanjiku');

        Sanctum::actingAs($driver);

        $this->assertContains($booking->id,
            $this->getJson('/api/auth/driver/bookings?status=confirmed')->assertOk()->json('bookings.*.id'));

        $this->postJson('/api/auth/driver/bookings/'.$booking->id.'/mark', ['action' => 'board'])
            ->assertOk();

        // Off the "To collect" tab, onto "Aboard" — one call, one passenger.
        $this->assertNotContains($booking->id,
            $this->getJson('/api/auth/driver/bookings?status=confirmed')->assertOk()->json('bookings.*.id'));
        $this->assertContains($booking->id,
            $this->getJson('/api/auth/driver/bookings?status=boarded')->assertOk()->json('bookings.*.id'));
    }

    #[Test]
    public function the_list_is_ordered_by_the_stops_he_will_reach(): void
    {
        [$driver, $queue, $world] = $this->trip();

        $far = $this->makePlace('Juja Stage');
        $near = $this->makePlace('Ruiru Stage');
        $this->makeRouteStage($world['route'], $far, 29.8);
        $this->makeRouteStage($world['route'], $near, 21.9);

        // Booked in the WRONG order on purpose: the far stop books first.
        $second = $this->paidBooking($queue, $world, $far, 'Books first, collected second');
        $first = $this->paidBooking($queue, $world, $near, 'Books second, collected first');

        Sanctum::actingAs($driver);

        $ids = $this->getJson('/api/auth/driver/bookings?status=confirmed')->assertOk()->json('bookings.*.id');

        // Route order, not booking order: Ruiru comes before Juja.
        $this->assertSame([$first->id, $second->id], $ids);
    }

    #[Test]
    public function a_no_show_leaves_the_collect_list_without_boarding(): void
    {
        [$driver, $queue, $world] = $this->trip();
        $booking = $this->paidBooking($queue, $world, $world['from'], 'Never showed');

        Sanctum::actingAs($driver);

        $this->postJson('/api/auth/driver/bookings/'.$booking->id.'/mark', ['action' => 'no_show'])
            ->assertOk();

        foreach (['confirmed', 'boarded'] as $tab) {
            $this->assertNotContains($booking->id,
                $this->getJson('/api/auth/driver/bookings?status='.$tab)->assertOk()->json('bookings.*.id'));
        }

        $this->assertFalse((bool) $booking->fresh()->boarded);
    }

    #[Test]
    public function the_stop_sweep_never_boards_someone_who_has_not_paid(): void
    {
        [$driver, $queue, $world] = $this->trip();

        $paid = $this->paidBooking($queue, $world, $world['from'], 'Paid');
        $unpaid = $this->makeBooking($queue, $world['owner'], $world['from'], $world['to'], 'Never paid');
        $cancelled = $this->makeBooking($queue, $world['owner'], $world['from'], $world['to'], 'Swept already');
        $cancelled->forceFill(['status' => false])->save();

        $stage = RouteStage::where('route_id', $world['route']->id)
            ->where('place_id', $world['from']->id)->firstOrFail();
        $queuePlace = $queue->queue_places()->create(['route_stage_id' => $stage->id, 'status' => true]);

        Sanctum::actingAs($this->makeUser(['Edit Passengers'], $world['sacco']));

        $this->postJson('/api/auth/bookings/passengers/pick', [
            'queueId' => $queue->id,
            'pickupId' => $queuePlace->id,
        ])->assertOk();

        // Only money that arrived counts as a ride taken. `boarded` is what the
        // manifest and the trip reports are built on.
        $this->assertTrue((bool) $paid->fresh()->boarded);
        $this->assertFalse((bool) $unpaid->fresh()->boarded, 'an unpaid booking is not a passenger');
        $this->assertFalse((bool) $cancelled->fresh()->boarded, 'a cancelled booking must not come back');
    }
}
