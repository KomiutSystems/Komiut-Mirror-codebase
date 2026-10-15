<?php

declare(strict_types=1);

namespace Tests\Feature\Booking;

use App\Enums\PaymentMethod;
use App\Models\Booking;
use App\Models\Queue;
use App\Models\User;
use App\Models\VehicleUser;
use App\Notifications\PlatformNotification;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Queues\QueueTestCase;

/**
 * "Your bus is arriving": a paid passenger is pushed once, the first time the
 * bus's live position comes within 200 m of their pickup.
 */
final class ArrivalAlertsTest extends QueueTestCase
{
    private const PING = '/api/v1/auth/book_a_ride/location';

    // The pickup, and points roughly 1 km and 100 m north of it.
    private const PICKUP = [-1.2833, 36.8167];

    private const FAR = [-1.2743, 36.8167];

    private const NEAR = [-1.2824, 36.8167];

    /** @return array{0: array, 1: Queue, 2: User} */
    private function liveTrip(): array
    {
        $world = $this->makeWorld();
        // The stop on the route is where a terminus booking is picked up.
        $world['from']->forceFill(['latitude' => self::PICKUP[0], 'longitude' => self::PICKUP[1]])->save();
        $world['stages'][0]->forceFill(['latitude' => self::PICKUP[0], 'longitude' => self::PICKUP[1]])->save();
        $run = $this->makeQueue(
            $world['vehicle'], $world['terminus'], $world['route'],
            $this->makeQueueStatus('Active '.$this->nextSequence(), 'Active'), $world['owner'],
        );
        $driver = $this->makeUser([], $world['sacco']);
        VehicleUser::create([
            'user_id' => $driver->id, 'vehicle_id' => $world['vehicle']->id,
            'sacco_id' => $world['sacco']->id, 'status' => true, 'start_date' => now(),
        ]);

        return [$world, $run, $driver];
    }

    private function booking(array $world, Queue $run, bool $paid): array
    {
        $passenger = $this->makeUser();
        $b = $this->makeBooking($run, $passenger, $world['from'], $world['to']);
        if ($paid) {
            $b->update(['paid' => true, 'payment_method' => PaymentMethod::Mpesa]);
        }

        return [$passenger, $b->fresh()];
    }

    /** "Your bus is arriving" pushes to $user; booking and payment notices are not counted. */
    private function alerts(User $user): int
    {
        return Notification::sent($user, PlatformNotification::class)
            ->filter(fn (PlatformNotification $n) => $n->title === 'Your bus is arriving')
            ->count();
    }

    private function ping(User $driver, Queue $run, array $at): void
    {
        Sanctum::actingAs($driver);
        $this->postJson(self::PING, ['latitude' => $at[0], 'longitude' => $at[1], 'queue_id' => $run->id])->assertStatus(202);
    }

    #[Test]
    public function a_paid_passenger_is_told_once_when_the_bus_comes_within_200_metres(): void
    {
        Notification::fake();
        [$world, $run, $driver] = $this->liveTrip();
        [$passenger, $booking] = $this->booking($world, $run, paid: true);

        $this->ping($driver, $run, self::FAR);
        $this->assertNull($booking->fresh()->arrival_alerted_at, 'a kilometre away is not arriving');
        $this->assertSame(0, $this->alerts($passenger));

        $this->ping($driver, $run, self::NEAR);
        $this->assertNotNull($booking->fresh()->arrival_alerted_at);
        $this->assertSame(1, $this->alerts($passenger));

        $this->ping($driver, $run, self::NEAR);
        $this->assertSame(1, $this->alerts($passenger), 'once, however many pings follow');
    }

    #[Test]
    public function nobody_who_has_not_paid_or_has_boarded_is_alerted(): void
    {
        Notification::fake();
        [$world, $run, $driver] = $this->liveTrip();
        [$unpaid, $hold] = $this->booking($world, $run, paid: false);
        [$aboard, $rode] = $this->booking($world, $run, paid: true);
        $rode->update(['boarded' => true]);

        $this->ping($driver, $run, self::NEAR);

        $this->assertSame(0, $this->alerts($unpaid));
        $this->assertSame(0, $this->alerts($aboard));
        $this->assertNull($hold->fresh()->arrival_alerted_at);
    }

    #[Test]
    public function a_roadside_passenger_is_measured_from_where_they_stand_not_the_stop(): void
    {
        Notification::fake();
        [$world, $run, $driver] = $this->liveTrip();
        [$passenger, $booking] = $this->booking($world, $run, paid: true);
        // Flagged down ~1 km before the stop the booking was snapped to.
        $booking->forceFill(['pickup_latitude' => self::FAR[0], 'pickup_longitude' => self::FAR[1]])->save();

        $this->ping($driver, $run, self::NEAR);
        $this->assertSame(0, $this->alerts($passenger), 'near the stop is not near them');

        $this->ping($driver, $run, self::FAR);
        $this->assertSame(1, $this->alerts($passenger));
    }

    #[Test]
    public function the_radius_is_configurable(): void
    {
        Notification::fake();
        config(['booking.arrival_alert_metres' => 2000]);
        [$world, $run, $driver] = $this->liveTrip();
        [$passenger] = $this->booking($world, $run, paid: true);

        $this->ping($driver, $run, self::FAR);

        $this->assertSame(1, $this->alerts($passenger));
    }
}
