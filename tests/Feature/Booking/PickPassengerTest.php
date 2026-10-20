<?php

declare(strict_types=1);

namespace Tests\Feature\Booking;

use App\Jobs\SendSMSJob;
use App\Models\Booking;
use Illuminate\Support\Facades\Queue as QueueFacade;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Queues\QueueTestCase;

/**
 * The office's single-passenger pick, guarded the way the stage sweep is.
 *
 * pickPassengers was hardened to PAID and ACTIVE only; its single-row sibling
 * was missed and went on setting boarded on a bare find(). `boarded` is what
 * the manifest and the trip reports are built on, and an unpaid booking that
 * IS boarded is still an unpaid hold to ReleaseExpiredBookings, which would
 * cancel it under a seated passenger.
 *
 * It also read $request->id, which Laravel resolves from the query string
 * before the route parameter, so /pick/5?id=999 boarded 999 and said 5.
 */
final class PickPassengerTest extends QueueTestCase
{
    /** @return array{0: Booking, 1: array} */
    private function bookingOnATrip(string $name = 'Wanjiku'): array
    {
        $world = $this->makeWorld();
        $active = $this->makeQueueStatus('Active', 'Active');
        $queue = $this->makeQueue($world['vehicle'], $world['terminus'], $world['route'], $active, $world['owner']);

        return [$this->makeBooking($queue, $world['owner'], $world['from'], $world['to'], $name), $world];
    }

    private function pick(int $id, array $query = []): TestResponse
    {
        $url = '/api/auth/bookings/passenger/pick/'.$id;

        return $this->getJson($query === [] ? $url : $url.'?'.http_build_query($query));
    }

    #[Test]
    public function an_unpaid_booking_is_not_boarded(): void
    {
        [$booking, $world] = $this->bookingOnATrip();
        Sanctum::actingAs($this->makeUser(['Edit Passengers'], $world['sacco']));

        $this->pick($booking->id)->assertStatus(409);

        $this->assertFalse((bool) $booking->fresh()->boarded, 'an unpaid booking is not a passenger');
    }

    #[Test]
    public function a_cancelled_booking_cannot_come_back(): void
    {
        [$booking, $world] = $this->bookingOnATrip();
        $booking->forceFill(['paid' => true, 'status' => false])->save();

        Sanctum::actingAs($this->makeUser(['Edit Passengers'], $world['sacco']));

        $this->pick($booking->id)->assertStatus(409);

        $this->assertFalse((bool) $booking->fresh()->boarded);
    }

    #[Test]
    public function a_paid_passenger_on_a_live_trip_still_boards(): void
    {
        [$booking, $world] = $this->bookingOnATrip();
        $booking->forceFill(['paid' => true])->save();

        Sanctum::actingAs($this->makeUser(['Edit Passengers'], $world['sacco']));

        $this->pick($booking->id)->assertOk();

        $fresh = $booking->fresh();
        $this->assertTrue((bool) $fresh->boarded);
        $this->assertNotNull($fresh->start_time);
    }

    #[Test]
    public function the_id_comes_from_the_route_not_the_query_string(): void
    {
        [$mine, $world] = $this->bookingOnATrip('The one in the URL');
        $mine->forceFill(['paid' => true])->save();

        [$other] = $this->bookingOnATrip('The one that must not move');
        $other->forceFill(['paid' => true])->save();

        Sanctum::actingAs($this->makeUser(['Edit Passengers'], $world['sacco']));

        // /pick/<mine>?id=<other> used to board $other and report $mine.
        $this->pick($mine->id, ['id' => $other->id])->assertOk();

        $this->assertTrue((bool) $mine->fresh()->boarded, 'the URL names who boards');
        $this->assertFalse((bool) $other->fresh()->boarded, 'the query string must not redirect the board');
    }

    #[Test]
    public function boarding_twice_does_not_text_the_passenger_twice(): void
    {
        QueueFacade::fake();

        [$booking, $world] = $this->bookingOnATrip();
        $booking->forceFill(['paid' => true])->save();

        Sanctum::actingAs($this->makeUser(['Edit Passengers'], $world['sacco']));

        $this->pick($booking->id)->assertOk();
        $this->pick($booking->id)->assertOk();

        $this->assertTrue((bool) $booking->fresh()->boarded);
        QueueFacade::assertPushed(SendSMSJob::class, 1);
    }
}
