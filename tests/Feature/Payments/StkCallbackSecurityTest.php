<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use App\Models\Booking;
use App\Models\MpesaBookingCallback;
use App\Models\MpesaStkCallback;
use Tests\Feature\Queues\QueueTestCase;

/**
 * Regression guard for the STK callback forgery fix.
 *
 * Before the fix, the Daraja callback URL carried `?booking_id=<sequential id>`
 * on an unauthenticated route and the handler trusted it, so anyone could POST
 * a forged success payload for a guessed booking and mark it paid. The callback
 * is now keyed by an unguessable per-payment nonce and resolves the booking
 * from the stored push record; replays are idempotent.
 *
 * Reuses QueueTestCase purely for its world/booking builders.
 */
final class StkCallbackSecurityTest extends QueueTestCase
{
    private function successPayload(): string
    {
        return json_encode([
            'Body' => [
                'stkCallback' => [
                    'MerchantRequestID' => 'merch-1',
                    'CheckoutRequestID' => 'checkout-1',
                    'ResultCode' => 0,
                    'ResultDesc' => 'The service request is processed successfully.',
                    'CallbackMetadata' => [
                        'Item' => [
                            ['Name' => 'Amount', 'Value' => 200],
                            ['Name' => 'MpesaReceiptNumber', 'Value' => 'ABC123XYZ'],
                            ['Name' => 'TransactionDate', 'Value' => '20260721120000'],
                            ['Name' => 'PhoneNumber', 'Value' => 254700111222],
                        ],
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR);
    }

    private function bookingWithPush(string $nonce): Booking
    {
        $world = $this->makeWorld();
        $status = $this->makeQueueStatus('Pending', 'Pending');
        $queue = $this->makeQueue($world['vehicle'], $world['terminus'], $world['route'], $status, $world['owner']);
        $booking = $this->makeBooking($queue, $world['owner'], $world['from'], $world['to']);

        MpesaStkCallback::create([
            'booking_id' => $booking->id,
            'callback_nonce' => $nonce,
            'callback' => '{}',
        ]);

        return $booking;
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function forged_callback_on_the_legacy_path_never_marks_a_booking_paid(): void
    {
        $booking = $this->bookingWithPush(str_repeat('a', 64));

        // Attacker guesses the sequential booking id on the retired endpoint.
        $response = $this->postJson('/api/stk/push/response?booking_id=' . $booking->id, json_decode($this->successPayload(), true));

        $response->assertStatus(410);
        $this->assertEquals(0, $booking->fresh()->paid, 'Legacy path must never mark a booking paid.');
        $this->assertSame(0, MpesaBookingCallback::count());
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function callback_with_an_unknown_nonce_is_rejected_and_marks_nothing(): void
    {
        $booking = $this->bookingWithPush(str_repeat('a', 64));

        $response = $this->call('POST', '/api/testing/stk/push/response/' . str_repeat('b', 64), [], [], [], [], $this->successPayload());

        $response->assertStatus(404);
        $this->assertEquals(0, $booking->fresh()->paid);
        $this->assertSame(0, MpesaBookingCallback::count());
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function valid_nonce_marks_the_resolved_booking_paid(): void
    {
        $nonce = str_repeat('c', 64);
        $booking = $this->bookingWithPush($nonce);

        $response = $this->call('POST', '/api/testing/stk/push/response/' . $nonce, [], [], [], [], $this->successPayload());

        $response->assertOk();
        $this->assertEquals(1, $booking->fresh()->paid, 'A genuine callback must mark the booking paid.');
        $this->assertSame(1, MpesaBookingCallback::where('booking_id', $booking->id)->count());
        $this->assertNotNull(MpesaStkCallback::where('callback_nonce', $nonce)->first()->processed_at);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function a_replayed_callback_is_idempotent(): void
    {
        $nonce = str_repeat('d', 64);
        $booking = $this->bookingWithPush($nonce);

        $this->call('POST', '/api/testing/stk/push/response/' . $nonce, [], [], [], [], $this->successPayload())->assertOk();
        // Daraja retries the same nonce.
        $this->call('POST', '/api/testing/stk/push/response/' . $nonce, [], [], [], [], $this->successPayload())->assertOk();

        $this->assertSame(
            1,
            MpesaBookingCallback::where('booking_id', $booking->id)->count(),
            'A replayed callback must not record a second payment.'
        );
    }
}
