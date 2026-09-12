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
            // Callback applied but nothing was marked paid -> the push failed.
            // `resultCode` stays 1 for the client that branches on it; the
            // DARAJA code and its meaning ride alongside, so the passenger can
            // be told "you cancelled" rather than "payment was not completed".
            return $this->reply('failed', 1, null, $this->outcome($record));
        }

        return $this->reply('processing', null);
    }

    /**
     * What actually happened, in words the passenger can act on.
     *
     * Daraja's ResultCode is stored on the record by the callback. The codes
     * below are the ones a passenger causes or can fix; anything else is
     * `failed` with Safaricom's own description passed through.
     *
     * @return array{darajaResultCode: ?int, reason: string, message: string}
     */
    private function outcome(MpesaStkCallback $record): array
    {
        $code = $record->result_code === null ? null : (int) $record->result_code;

        [$reason, $message] = match ($code) {
            1032 => ['cancelled_by_user', 'You cancelled the M-Pesa prompt.'],
            1037 => ['no_pin_in_time', 'The M-Pesa prompt timed out before a PIN was entered. Try again and enter your PIN when it appears.'],
            1 => ['insufficient_funds', 'Your M-Pesa balance is not enough for this fare.'],
            2001 => ['wrong_pin', 'The M-Pesa PIN entered was wrong.'],
            1001 => ['phone_busy', 'Your phone is busy with another M-Pesa session. Wait a moment and try again.'],
            1019 => ['expired', 'This payment request expired. Try again.'],
            1025, 1026 => ['not_delivered', 'The M-Pesa prompt could not reach your phone. Check your network and try again.'],
            default => ['failed', $record->result_desc ?: 'Payment was not completed.'],
        };

        return ['darajaResultCode' => $code, 'reason' => $reason, 'message' => $message];
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
            // The passenger, or whoever CREATED the booking: the push lets a
            // conductor holding Edit Passengers start a payment on a walk-in's
            // behalf (the booking carries the conductor as created_by), and
            // until now this check let only user_id poll it -- so the same
            // conductor's status poll answered not_found for a push they had
            // just started, with the passenger standing there.
            return Booking::withoutGlobalScopes()
                ->whereKey($record->booking_id)
                ->where(fn ($q) => $q->where('user_id', $userId)->orWhere('created_by', $userId))
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

    /** @param  array<string, mixed>  $extra */
    private function reply(string $status, ?int $resultCode, ?string $receipt = null, array $extra = []): JsonResponse
    {
        return response()->json([
            'status' => $status,
            'resultCode' => $resultCode,
            'mpesaReceiptNumber' => $receipt,
        ] + $extra);
    }
}
