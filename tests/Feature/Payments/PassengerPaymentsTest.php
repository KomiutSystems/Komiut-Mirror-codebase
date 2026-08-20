<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use App\Models\Booking;
use App\Models\MpesaBookingCallback;
use App\Models\Queue;
use App\Models\User;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Queues\QueueTestCase;

/**
 * Passenger-scoped payment history.
 *
 * App\Http\Controllers\APIs\Passenger\PassengerPaymentsController::index
 * derives a passenger's payments from THEIR OWN paid bookings (+ the M-Pesa
 * receipt on each), because the dashboard `transactions` endpoint is gated by
 * `permission:View Transactions` and 403s a normal passenger.
 *
 * Reuses the queue/booking scaffolding from the Queues suite so the fixtures
 * (place -> route -> vehicle -> queue) match how real bookings are shaped.
 */
final class PassengerPaymentsTest extends QueueTestCase
{
    /**
     * @return array{queue: Queue, world: array<string, mixed>}
     */
    private function makeQueueWorld(): array
    {
        $world = $this->makeWorld();
        $pending = $this->makeQueueStatus('Pending', 'Pending');
        $queue = $this->makeQueue($world['vehicle'], $world['terminus'], $world['route'], $pending, $world['owner']);

        return ['queue' => $queue, 'world' => $world];
    }

    /** A paid booking for $user with an M-Pesa receipt recorded against it. */
    private function makePaidBooking(
        Queue $queue,
        User $user,
        array $world,
        float $amount = 200,
        string $receipt = 'QGH7XY12ZK',
        string $method = 'mpesa'
    ): Booking {
        $booking = $this->makeBooking($queue, $user, $world['from'], $world['to'], 'Wanjiku');
        $booking->forceFill(['paid' => true, 'amount' => $amount, 'payment_method' => $method])->save();

        MpesaBookingCallback::create([
            'transid' => $receipt,
            'name' => 'Wanjiku',
            'amount' => $amount,
            'phone' => '254700111222',
            'transdate' => Carbon::now(),
            'booking_id' => $booking->id,
            // callback is NOT NULL — it holds the raw M-Pesa payload.
            'callback' => json_encode(['TransID' => $receipt, 'TransAmount' => $amount]),
        ]);

        return $booking;
    }

    #[Test]
    public function a_passenger_sees_their_own_paid_bookings_as_payments(): void
    {
        ['queue' => $queue, 'world' => $world] = $this->makeQueueWorld();
        $passenger = $this->makeUser([], $world['sacco']);
        $this->makePaidBooking($queue, $passenger, $world, 250, 'QGH7XY12ZK', 'mpesa');

        Sanctum::actingAs($passenger);

        $this->getJson('/api/auth/payments/history')
            ->assertOk()
            ->assertJsonCount(1, 'payments')
            // JSON encodes the (float) 250.0 as 250, which decodes back to int.
            ->assertJsonPath('payments.0.amount', 250)
            ->assertJsonPath('payments.0.method', 'mpesa')
            ->assertJsonPath('payments.0.mpesa_receipt_number', 'QGH7XY12ZK')
            ->assertJsonStructure([
                'payments' => [['booking_id', 'reference', 'amount', 'date', 'method', 'mpesa_receipt_number', 'route', 'plate', 'passengers']],
                'total', 'per_page', 'current_page', 'last_page',
            ]);
    }

    #[Test]
    public function another_users_payments_are_excluded(): void
    {
        ['queue' => $queue, 'world' => $world] = $this->makeQueueWorld();
        $passenger = $this->makeUser([], $world['sacco']);
        $other = $this->makeUser([], $world['sacco']);

        $mine = $this->makePaidBooking($queue, $passenger, $world, 200, 'MINE00001');
        $this->makePaidBooking($queue, $other, $world, 400, 'THEIRS0001');

        Sanctum::actingAs($passenger);

        $this->getJson('/api/auth/payments/history')
            ->assertOk()
            ->assertJsonCount(1, 'payments')
            ->assertJsonPath('payments.0.booking_id', $mine->id)
            ->assertJsonPath('payments.0.mpesa_receipt_number', 'MINE00001');
    }

    #[Test]
    public function unpaid_bookings_are_excluded(): void
    {
        ['queue' => $queue, 'world' => $world] = $this->makeQueueWorld();
        $passenger = $this->makeUser([], $world['sacco']);

        // One paid, one still an unpaid hold.
        $this->makePaidBooking($queue, $passenger, $world, 200, 'PAID00001');
        $unpaid = $this->makeBooking($queue, $passenger, $world['from'], $world['to'], 'Otieno');
        $unpaid->forceFill(['paid' => false])->save();

        Sanctum::actingAs($passenger);

        $this->getJson('/api/auth/payments/history')
            ->assertOk()
            ->assertJsonCount(1, 'payments')
            ->assertJsonPath('payments.0.mpesa_receipt_number', 'PAID00001');
    }

    #[Test]
    public function the_endpoint_rejects_an_unauthenticated_caller(): void
    {
        $this->getJson('/api/auth/payments/history')
            ->assertStatus(401);
    }

    #[Test]
    public function the_history_can_be_filtered_by_a_date_range(): void
    {
        ['queue' => $queue, 'world' => $world] = $this->makeQueueWorld();
        $passenger = $this->makeUser([], $world['sacco']);

        $old = $this->makePaidBooking($queue, $passenger, $world, 200, 'OLD000001');
        $old->forceFill(['created_at' => Carbon::now()->subDays(10)])->save();

        $recent = $this->makePaidBooking($queue, $passenger, $world, 300, 'NEW000001');

        Sanctum::actingAs($passenger);

        // Only the last 3 days: the 10-day-old payment is excluded.
        $this->getJson('/api/auth/payments/history?from_date='.Carbon::now()->subDays(3)->toDateString())
            ->assertOk()
            ->assertJsonCount(1, 'payments')
            ->assertJsonPath('payments.0.booking_id', $recent->id);

        // Widen the window to include both.
        $this->getJson('/api/auth/payments/history?from_date='.Carbon::now()->subDays(20)->toDateString().'&to_date='.Carbon::now()->toDateString())
            ->assertOk()
            ->assertJsonCount(2, 'payments');
    }
}
