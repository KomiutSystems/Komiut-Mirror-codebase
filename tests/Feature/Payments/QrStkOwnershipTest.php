<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use App\Models\MpesaQrcodePayment;
use App\Models\MpesaStkCallback;
use App\Models\QrcodePayment;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Queues\QueueTestCase;

/**
 * QR-code STK ownership (blocker #2).
 *
 * customerQRCodeSTKPush now stamps the QrcodePayment with the authenticated
 * caller's id. StkStatusController resolves ownership of a QR push through that
 * QrcodePayment.user_id (mirroring how the booking flow resolves it through
 * Booking.user_id). Without the owner set, the passenger's own status poll fell
 * through ownedByCaller() and every successful QR payment read as not_found /
 * FAILED. These tests pin the record shape the fixed controller produces and
 * prove the poll now finds it as owned.
 */
final class QrStkOwnershipTest extends QueueTestCase
{
    /**
     * A pending QR STK push for $payer, exactly as customerQRCodeSTKPush now
     * persists it: QrcodePayment.user_id = the authenticated caller, plus the
     * MpesaStkCallback keyed by qrcode_payment_id.
     *
     * @return array{0: QrcodePayment, 1: MpesaStkCallback}
     */
    private function pendingQrPush(?User $owner): array
    {
        $world = $this->makeWorld();

        $payment = QrcodePayment::create([
            'vehicle_id' => $world['vehicle']->id,
            'user_id' => $owner?->id,
            'amount' => 120,
        ]);

        $push = MpesaStkCallback::create([
            'qrcode_payment_id' => $payment->id,
            'callback_nonce' => 'nonce-'.$this->nextSequence(),
            'checkout_request_id' => 'ws_CO_'.$this->nextSequence(),
            'callback' => json_encode(['CheckoutRequestID' => 'ws_CO']),
        ]);

        return [$payment, $push];
    }

    #[Test]
    public function the_qr_payment_records_the_authenticated_owner(): void
    {
        // customerQRCodeSTKPush sets user_id = auth()->id(); the poll matches on
        // exactly that column, so this is the link that was missing.
        $payer = $this->makeUser();
        [$payment] = $this->pendingQrPush($payer);

        $this->assertSame($payer->id, $payment->fresh()->user_id);
    }

    #[Test]
    public function the_owner_sees_their_pending_qr_push_as_processing(): void
    {
        // The bug: this used to read as not_found because user_id was never set.
        $payer = $this->makeUser();
        [, $push] = $this->pendingQrPush($payer);
        Sanctum::actingAs($payer);

        $this->getJson('/api/auth/mpesa/stk/status/'.$push->checkout_request_id)
            ->assertOk()
            ->assertJsonPath('status', 'processing')
            ->assertJsonPath('resultCode', null);
    }

    #[Test]
    public function a_settled_qr_push_reads_as_completed_with_receipt(): void
    {
        $payer = $this->makeUser();
        [$payment, $push] = $this->pendingQrPush($payer);
        $payment->forceFill(['status' => true])->save();
        MpesaQrcodePayment::create([
            'transid' => 'SFT77QRC99',
            'phone' => '254700111222',
            'amount' => 120,
            'transdate' => now(),
            'qrcode_payment_id' => $payment->id,
            'callback' => '{}',
        ]);
        Sanctum::actingAs($payer);

        $this->getJson('/api/auth/mpesa/stk/status/'.$push->checkout_request_id)
            ->assertOk()
            ->assertJsonPath('status', 'completed')
            ->assertJsonPath('resultCode', 0)
            ->assertJsonPath('mpesaReceiptNumber', 'SFT77QRC99');
    }

    #[Test]
    public function another_users_qr_push_is_indistinguishable_from_not_found(): void
    {
        $owner = $this->makeUser();
        [, $push] = $this->pendingQrPush($owner);

        Sanctum::actingAs($this->makeUser()); // a different passenger
        $this->getJson('/api/auth/mpesa/stk/status/'.$push->checkout_request_id)
            ->assertOk()
            ->assertJsonPath('status', 'not_found');
    }

    #[Test]
    public function an_ownerless_qr_push_is_not_found_even_to_the_caller(): void
    {
        // Regression guard for the old behaviour: with no owner recorded, nobody
        // can poll it — which is exactly why the fix must stamp user_id.
        [, $push] = $this->pendingQrPush(null);
        Sanctum::actingAs($this->makeUser());

        $this->getJson('/api/auth/mpesa/stk/status/'.$push->checkout_request_id)
            ->assertOk()
            ->assertJsonPath('status', 'not_found');
    }
}
