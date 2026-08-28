<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use App\Models\Booking;
use App\Models\Place;
use App\Models\Queue;
use App\Models\SeatArrangement;
use App\Models\SeatBooking;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Queues\QueueTestCase;

/**
 * app:check-passenger-payments — the two-minute unpaid sweep.
 *
 * The sweep reads a set of unpaid booking ids and then writes to two tables, so
 * an M-Pesa callback that settles one of those bookings in between used to lose
 * the race: the write carried no unpaid check of its own and cancelled a booking
 * whose money had already arrived. The middle test here reproduces exactly that
 * interleaving; the others pin the behaviour the sweep is actually for.
 */
final class UnpaidBookingSweepTest extends QueueTestCase
{
    /**
     * A live (Pending) queue with a passenger ready to book onto it.
     *
     * @return array{0: array<string, mixed>, 1: Queue, 2: User}
     */
    private function stage(string $queueStatus = 'Pending'): array
    {
        $world = $this->makeWorld();
        $status = $this->makeQueueStatus($queueStatus, $queueStatus);
        $queue = $this->makeQueue($world['vehicle'], $world['terminus'], $world['route'], $status, $world['owner']);

        return [$world, $queue, $this->makeUser([], $world['sacco'])];
    }

    /**
     * An unpaid booking holding one seat, optionally aged past the payment window.
     *
     * @param  array<string, mixed>  $world
     */
    private function hold(array $world, Queue $queue, User $passenger, int $seatIndex, int $ageMinutes): Booking
    {
        /** @var Place $from */
        $from = $world['from'];
        /** @var Place $to */
        $to = $world['to'];
        /** @var SeatArrangement $seat */
        $seat = $world['arrangements'][$seatIndex];

        $booking = $this->makeBooking($queue, $passenger, $from, $to, "Passenger {$seatIndex}");
        $this->makeSeatBooking($booking, $seat);

        if ($ageMinutes > 0) {
            $booking->forceFill(['created_at' => now()->subMinutes($ageMinutes)])->save();
        }

        return $booking;
    }

    private function seatStatus(Booking $booking): bool
    {
        return (bool) SeatBooking::where('booking_id', $booking->id)->value('status');
    }

    #[Test]
    public function an_unpaid_booking_past_the_window_is_cancelled_and_its_seat_released(): void
    {
        [$world, $queue, $passenger] = $this->stage();
        $stale = $this->hold($world, $queue, $passenger, 0, 5);

        $this->artisan('app:check-passenger-payments')->assertExitCode(0);

        $this->assertFalse((bool) $stale->fresh()->status);
        $this->assertFalse($this->seatStatus($stale));
    }

    #[Test]
    public function a_booking_paid_between_the_read_and_the_write_keeps_its_seat(): void
    {
        [$world, $queue, $passenger] = $this->stage();

        $racer = $this->hold($world, $queue, $passenger, 0, 5);
        $loser = $this->hold($world, $queue, $passenger, 1, 5);

        // The gap the bug lives in: the sweep has plucked the ids and has not
        // written yet. DB::listen fires between those two statements, so landing
        // the callback from here is the real interleaving, not an approximation.
        $settled = false;
        DB::listen(function (QueryExecuted $query) use (&$settled, $racer): void {
            $sql = strtolower($query->sql);

            if ($settled || ! str_starts_with(ltrim($sql), 'select') || ! str_contains($sql, 'bookings')) {
                return;
            }

            $settled = true;

            // Straight to the table: a model save would re-enter this listener
            // and fire BookingPaid, neither of which is what is under test.
            DB::table('bookings')->where('id', $racer->id)->update(['paid' => true]);
        });

        $this->artisan('app:check-passenger-payments')->assertExitCode(0);

        $this->assertTrue($settled, 'the simulated M-Pesa callback never fired');

        // Paid mid-sweep: the booking stays live and keeps the seat it paid for.
        $this->assertTrue((bool) $racer->fresh()->status);
        $this->assertTrue($this->seatStatus($racer));

        // Its neighbour never paid, so the sweep still does its job.
        $this->assertFalse((bool) $loser->fresh()->status);
        $this->assertFalse($this->seatStatus($loser));
    }

    #[Test]
    public function a_booking_still_inside_the_payment_window_is_left_alone(): void
    {
        [$world, $queue, $passenger] = $this->stage();
        $recent = $this->hold($world, $queue, $passenger, 0, 0);

        $this->artisan('app:check-passenger-payments')->assertExitCode(0);

        $this->assertTrue((bool) $recent->fresh()->status);
        $this->assertTrue($this->seatStatus($recent));
    }

    #[Test]
    public function a_booking_already_paid_is_never_swept(): void
    {
        [$world, $queue, $passenger] = $this->stage();
        $paid = $this->hold($world, $queue, $passenger, 0, 5);
        $paid->update(['paid' => true]);

        $this->artisan('app:check-passenger-payments')->assertExitCode(0);

        $this->assertTrue((bool) $paid->fresh()->status);
        $this->assertTrue($this->seatStatus($paid));
    }

    #[Test]
    public function a_booking_on_a_queue_that_is_no_longer_live_is_left_alone(): void
    {
        [$world, $queue, $passenger] = $this->stage('Completed');
        $stale = $this->hold($world, $queue, $passenger, 0, 5);

        $this->artisan('app:check-passenger-payments')->assertExitCode(0);

        $this->assertTrue((bool) $stale->fresh()->status);
        $this->assertTrue($this->seatStatus($stale));
    }
}
