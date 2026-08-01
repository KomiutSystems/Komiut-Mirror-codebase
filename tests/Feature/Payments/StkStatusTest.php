<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use App\Models\Booking;
use App\Models\MpesaBookingCallback;
use App\Models\MpesaStkCallback;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Queues\QueueTestCase;

/**
 * STK status poll + cancel (App\Http\Controllers\APIs\Payments\StkStatusController).
 *
 * Status is derived from local settlement state, scoped to the caller's own
 * payment. Cancel is local intent only and must never override a settled payment.
 */
final class StkStatusTest extends QueueTestCase
{
    /** A pending STK push for a booking owned by $payer. */
    private function pendingPush(User $payer, array $overrides = []): array
    {
        $world = $this->makeWorld();
        $pending = $this->makeQueueStatus('Pending', 'Pending');
        $queue = $this->makeQueue($world['vehicle'], $world['terminus'], $world['route'], $pending, $world['owner']);
        $booking = $this->makeBooking($queue, $payer, $world['from'], $world['to'], 'Payer');

        $push = MpesaStkCallback::create(array_merge([
            'booking_id' => $booking->id,
            'callback_nonce' => 'nonce-' . $this->nextSequence(),
            'checkout_request_id' => 'ws_CO_' . $this->nextSequence(),
            'callback' => json_encode(['CheckoutRequestID' => 'ws_CO']),
        ], $overrides));

        return [$booking, $push];
    }

    #[Test]
    public function a_pending_push_reads_as_processing(): void
    {
        $payer = $this->makeUser();
        [, $push] = $this->pendingPush($payer);
        Sanctum::actingAs($payer);

        $this->getJson('/api/auth/mpesa/stk/status/' . $push->checkout_request_id)
            ->assertOk()
            ->assertJsonPath('status', 'processing')
            ->assertJsonPath('resultCode', null);
    }

    #[Test]
    public function a_paid_booking_reads_as_completed_with_receipt(): void
    {
        $payer = $this->makeUser();
        [$booking, $push] = $this->pendingPush($payer);
        $booking->forceFill(['paid' => true])->save();
        MpesaBookingCallback::create([
            'transid' => 'SFT12ABC34', 'phone' => '254700111222', 'amount' => 200,
            'transdate' => now(), 'booking_id' => $booking->id, 'callback' => '{}',
        ]);
        Sanctum::actingAs($payer);

        $this->getJson('/api/auth/mpesa/stk/status/' . $push->checkout_request_id)
            ->assertOk()
            ->assertJsonPath('status', 'completed')
            ->assertJsonPath('resultCode', 0)
            ->assertJsonPath('mpesaReceiptNumber', 'SFT12ABC34');
    }

    #[Test]
    public function a_processed_but_unpaid_push_reads_as_failed(): void
    {
        $payer = $this->makeUser();
        [, $push] = $this->pendingPush($payer, ['processed_at' => now()]);
        Sanctum::actingAs($payer);

        $this->getJson('/api/auth/mpesa/stk/status/' . $push->checkout_request_id)
            ->assertOk()
            ->assertJsonPath('status', 'failed');
    }

    #[Test]
    public function an_unknown_checkout_id_reads_as_not_found(): void
    {
        Sanctum::actingAs($this->makeUser());

        $this->getJson('/api/auth/mpesa/stk/status/does-not-exist')
            ->assertOk()
            ->assertJsonPath('status', 'not_found');
    }

    #[Test]
    public function another_users_payment_is_indistinguishable_from_not_found(): void
    {
        $owner = $this->makeUser();
        [, $push] = $this->pendingPush($owner);

        Sanctum::actingAs($this->makeUser()); // a different passenger
        $this->getJson('/api/auth/mpesa/stk/status/' . $push->checkout_request_id)
            ->assertOk()
            ->assertJsonPath('status', 'not_found');
    }

    #[Test]
    public function cancel_marks_a_pending_push(): void
    {
        $payer = $this->makeUser();
        [, $push] = $this->pendingPush($payer);
        Sanctum::actingAs($payer);

        $this->postJson('/api/auth/mpesa/stk/cancel/' . $push->checkout_request_id)
            ->assertOk()
            ->assertJsonPath('status', 'cancelled');

        $this->assertNotNull($push->fresh()->cancelled_at);

        $this->getJson('/api/auth/mpesa/stk/status/' . $push->checkout_request_id)
            ->assertJsonPath('status', 'cancelled');
    }

    #[Test]
    public function cancel_cannot_override_a_settled_payment(): void
    {
        $payer = $this->makeUser();
        [$booking, $push] = $this->pendingPush($payer);
        $booking->forceFill(['paid' => true])->save();
        Sanctum::actingAs($payer);

        $this->postJson('/api/auth/mpesa/stk/cancel/' . $push->checkout_request_id)
            ->assertStatus(409);

        $this->assertNull($push->fresh()->cancelled_at);
    }

    #[Test]
    public function paid_wins_even_after_a_cancel(): void
    {
        // Cancel is local intent; if the payment nonetheless completes, the
        // reconciler marks the booking paid and status must report completed.
        $payer = $this->makeUser();
        [$booking, $push] = $this->pendingPush($payer, ['cancelled_at' => now()]);
        $booking->forceFill(['paid' => true])->save();
        Sanctum::actingAs($payer);

        $this->getJson('/api/auth/mpesa/stk/status/' . $push->checkout_request_id)
            ->assertOk()
            ->assertJsonPath('status', 'completed');
    }
}
