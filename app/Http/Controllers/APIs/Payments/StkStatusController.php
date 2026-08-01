<?php

declare(strict_types=1);

namespace App\Http\Controllers\APIs\Payments;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\MpesaBookingCallback;
use App\Models\MpesaQrcodePayment;
use App\Models\MpesaStkCallback;
use App\Models\QrcodePayment;
use Illuminate\Http\JsonResponse;

/**
 * @group Payments — M-Pesa STK
 *
 * The passenger app initiates an STK push, then polls this for the outcome and
 * can cancel a push the user abandoned. Status is read from local state only:
 * the confirmation webhook lands within seconds in the normal case, and the
 * `payments:reconcile` poller recovers the rare lost callback — so this endpoint
 * never calls Safaricom and never writes to a booking, keeping it fast and
 * incapable of the "notification/query rolled back a paid booking" failure mode.
 *
 * Status vocabulary matches the app's model: processing | completed | failed |
 * cancelled | not_found, with resultCode (0 paid, 1 failed/cancelled, null
 * pending) and mpesaReceiptNumber once completed.
 */
class StkStatusController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }

    /**
     * Poll an STK payment's status
     *
     * @authenticated
     *
     * @urlParam checkout string required The CheckoutRequestID from the push. Example: ws_CO_28062026...
     *
     * @response 200 {"status": "completed", "resultCode": 0, "mpesaReceiptNumber": "SFT12ABC34"}
     * @response 200 {"status": "not_found", "resultCode": null}
     */
    public function status(string $checkout): JsonResponse
    {
        $record = MpesaStkCallback::where('checkout_request_id', $checkout)->first();

        if ($record === null || ! $this->ownedByCaller($record)) {
            // Same shape for "never existed" and "not yours" — don't leak which.
            return $this->reply('not_found', null);
        }

        [$paid, $receipt] = $this->settlement($record);

        if ($paid) {
            return $this->reply('completed', 0, $receipt);
        }
        if ($record->cancelled_at !== null) {
            return $this->reply('cancelled', 1);
        }
        if ($record->processed_at !== null) {
            // Callback applied but nothing was marked paid → the push failed.
            return $this->reply('failed', 1);
        }

        return $this->reply('processing', null);
    }

    /**
     * Cancel a pending STK push
     *
     * Records that the user backed out before entering their PIN. Local intent
     * only — Safaricom has no cancel API — so if the payment nonetheless
     * completes, the reconciler still confirms it (paid always wins over cancel).
     *
     * @authenticated
     *
     * @response 200 {"status": "cancelled"}
     * @response 409 {"error": "This payment is already settled."}
     */
    public function cancel(string $checkout): JsonResponse
    {
        $record = MpesaStkCallback::where('checkout_request_id', $checkout)->first();

        if ($record === null || ! $this->ownedByCaller($record)) {
            return response()->json(['error' => 'Payment not found.'], 404);
        }

        [$paid] = $this->settlement($record);
        if ($paid || $record->processed_at !== null) {
            return response()->json(['error' => 'This payment is already settled.'], 409);
        }

        if ($record->cancelled_at === null) {
            $record->forceFill(['cancelled_at' => now()])->save();
        }

        return response()->json(['status' => 'cancelled']);
    }

    /**
     * Whether this push settled, and its receipt. Handles both the booking STK
     * flow (booking.paid + MpesaBookingCallback) and the QR flow
     * (QrcodePayment.status + MpesaQrcodePayment).
     *
     * @return array{0: bool, 1: string|null}
     */
    private function settlement(MpesaStkCallback $record): array
    {
        if ($record->booking_id > 0) {
            $booking = Booking::withoutGlobalScopes()->find($record->booking_id);
            if ($booking?->paid) {
                $receipt = MpesaBookingCallback::where('booking_id', $booking->id)->value('transid');

                return [true, $receipt];
            }

            return [false, null];
        }

        if ($record->qrcode_payment_id > 0) {
            $payment = QrcodePayment::withoutGlobalScopes()->find($record->qrcode_payment_id);
            if ($payment?->status) {
                $receipt = MpesaQrcodePayment::where('qrcode_payment_id', $payment->id)->value('transid');

                return [true, $receipt];
            }
        }

        return [false, null];
    }

    /** A passenger may only poll their own payment. */
    private function ownedByCaller(MpesaStkCallback $record): bool
    {
        $userId = auth()->id();

        if ($record->booking_id > 0) {
            return Booking::withoutGlobalScopes()
                ->whereKey($record->booking_id)
                ->where('user_id', $userId)
                ->exists();
        }

        if ($record->qrcode_payment_id > 0) {
            return QrcodePayment::withoutGlobalScopes()
                ->whereKey($record->qrcode_payment_id)
                ->where('user_id', $userId)
                ->exists();
        }

        return false;
    }

    private function reply(string $status, ?int $resultCode, ?string $receipt = null): JsonResponse
    {
        return response()->json([
            'status' => $status,
            'resultCode' => $resultCode,
            'mpesaReceiptNumber' => $receipt,
        ]);
    }
}
