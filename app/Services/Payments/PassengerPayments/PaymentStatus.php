<?php

declare(strict_types=1);

namespace App\Services\Payments\PassengerPayments;

use App\Enums\BookingCancellationReason;
use App\Enums\LoyaltyTransactionType;
use App\Models\Booking;
use App\Models\MpesaStkCallback;
use App\Models\QrcodePayment;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * THE one reading of a passenger's payment status. Every other place that
 * needs it -- the Payments tab, its single-payment read, payment.updated, the
 * fare push -- asks here.
 *
 * WHY ONE PLACE. Nothing stores "the status of a payment". A booking keeps
 * three booleans and a cancellation reason; a QR fare keeps one boolean; the
 * push behind either keeps processed_at, cancelled_at, unconfirmed_at and
 * whatever Safaricom or the bank answered; a refund is a row on the loyalty
 * ledger. Read separately, the list, the socket and the push would each have
 * reached its own verdict on the same row, and they would not have agreed --
 * StkStatusController and BookingStatus already describe the same booking in
 * different words.
 *
 * FIVE STATUSES, nothing else:
 *   pending    a prompt is out (or a seat is held) and nothing is decided
 *   paid       the money, points or wallet arrived
 *   failed     Safaricom or the bank said no, or never answered in time
 *   cancelled  the passenger backed out in the app
 *   refunded   paid, then given back (not boarded, or the trip ended first)
 *
 * The SQL predicates at the bottom say exactly what the PHP above them says,
 * for the list's ?status= filter: filtering in PHP would mean loading the
 * whole history to fill one page. PassengerPaymentFeedTest reads every
 * fixture both ways (the row, and the filter) so the two cannot drift.
 *
 * The passenger is shown the sentences below, never a raw Daraja or bank
 * string. docs/passenger-payments-api.md lists them verbatim.
 */
final class PaymentStatus
{
    public const PENDING = 'pending';

    public const PAID = 'paid';

    public const FAILED = 'failed';

    public const CANCELLED = 'cancelled';

    public const REFUNDED = 'refunded';

    public const ALL = [self::PENDING, self::PAID, self::FAILED, self::CANCELLED, self::REFUNDED];

    /** ?status=active is what the app's "pinned while they settle" asks for. */
    public const ACTIVE = 'active';

    /**
     * When an unanswered push is given up on. Daraja keeps a prompt on the
     * handset for two minutes (MpesaPaymentsController::PROMPT_LIFETIME_SECONDS)
     * and a callback normally follows within seconds; ten minutes is that
     * plus every retry Safaricom and our own reconcile get.
     */
    public const UNCONFIRMED_AFTER_SECONDS = 600;

    /**
     * How far back the sweep stamps unconfirmed_at. A push older than this was
     * raised before the sweep existed and is read as unconfirmed without one --
     * stamping a year of old pushes would announce a year of old failures.
     */
    public const SWEEP_LOOKBACK_SECONDS = 86400;

    /**
     * A QR fare is written before its push: the push row follows Daraja's
     * answer, which can take the 20s request timeout. A fare with no push at
     * all after this long never got a prompt out.
     */
    public const PUSH_IN_FLIGHT_SECONDS = 120;

    // What the passenger reads. Short, in their terms, and never Safaricom's.
    public const MSG_CANCELLED_ON_PHONE = 'You cancelled the M-Pesa prompt.';

    public const MSG_INSUFFICIENT_FUNDS = 'Not enough money in M-Pesa.';

    public const MSG_WRONG_PIN = 'The M-Pesa PIN was wrong.';

    public const MSG_NO_PIN = 'The M-Pesa prompt timed out before a PIN was entered.';

    public const MSG_PHONE_BUSY = 'Your phone was busy with another M-Pesa request.';

    public const MSG_REQUEST_EXPIRED = 'The M-Pesa request expired.';

    public const MSG_NOT_DELIVERED = 'The M-Pesa prompt could not reach your phone.';

    public const MSG_DECLINED = 'M-Pesa did not complete this payment.';

    public const MSG_NOT_SENT = 'The M-Pesa prompt could not be sent.';

    public const MSG_UNCONFIRMED = 'M-Pesa did not confirm this payment. If money left your account, contact support with the time and amount.';

    public const MSG_CANCELLED_IN_APP = 'You cancelled this payment in the app.';

    public const MSG_BOOKING_CANCELLED = 'This booking was cancelled before it was paid.';

    public const MSG_BOOKING_EXPIRED = 'The booking expired before it was paid.';

    public const MSG_TRIP_ENDED_UNPAID = 'The trip ended before this booking was paid.';

    public const MSG_REFUNDED_NO_SHOW = 'You were not marked as boarded, so the fare was refunded as points.';

    public const MSG_REFUNDED_TRIP_OVER = 'The trip ended before you boarded, so the fare was refunded as points.';

    public const MSG_REFUNDED = 'The fare was refunded as points.';

    /** Every sentence status_message can carry -- the doc and its test list these. */
    public const MESSAGES = [
        self::MSG_CANCELLED_ON_PHONE, self::MSG_INSUFFICIENT_FUNDS, self::MSG_WRONG_PIN, self::MSG_NO_PIN,
        self::MSG_PHONE_BUSY, self::MSG_REQUEST_EXPIRED, self::MSG_NOT_DELIVERED, self::MSG_DECLINED,
        self::MSG_NOT_SENT, self::MSG_UNCONFIRMED, self::MSG_CANCELLED_IN_APP, self::MSG_BOOKING_CANCELLED,
        self::MSG_BOOKING_EXPIRED, self::MSG_TRIP_ENDED_UNPAID, self::MSG_REFUNDED_NO_SHOW,
        self::MSG_REFUNDED_TRIP_OVER, self::MSG_REFUNDED,
    ];

    /**
     * Why a push did not take money, in the passenger's words.
     *
     * Daraja's ResultCode when there is one. NCBA and Co-op are recorded with
     * no code (their numbers mean other things -- see CoopStkSettlement) and
     * only a description, which is read for the same meanings CoopStkAnswer
     * reads it for. Anything unrecognised is the plain "did not complete":
     * Safaricom's own wording ("DS timeout user cannot be reached",
     * "Request cancelled by user") is not something to put in front of anyone.
     */
    public static function messageForResult(?int $code, ?string $description): string
    {
        $byCode = match ($code) {
            1032 => self::MSG_CANCELLED_ON_PHONE,
            1 => self::MSG_INSUFFICIENT_FUNDS,
            2001 => self::MSG_WRONG_PIN,
            1037 => self::MSG_NO_PIN,
            1001 => self::MSG_PHONE_BUSY,
            1019 => self::MSG_REQUEST_EXPIRED,
            1025, 1026 => self::MSG_NOT_DELIVERED,
            default => null,
        };
        if ($byCode !== null) {
            return $byCode;
        }

        $said = (string) $description;

        return match (true) {
            (bool) preg_match('/cancell?ed\s+by\s+(the\s+)?(user|customer)/i', $said) => self::MSG_CANCELLED_ON_PHONE,
            (bool) preg_match('/insufficient/i', $said) => self::MSG_INSUFFICIENT_FUNDS,
            (bool) preg_match('/(wrong|invalid|incorrect)\s+pin/i', $said) => self::MSG_WRONG_PIN,
            (bool) preg_match('/ds\s+timeout|cannot\s+be\s+reached|tim(e|ed)\s?out/i', $said) => self::MSG_NO_PIN,
            (bool) preg_match('/expired/i', $said) => self::MSG_REQUEST_EXPIRED,
            default => self::MSG_DECLINED,
        };
    }

    /**
     * Did the provider refuse this push outright, so no prompt ever went out?
     *
     * A refusal is an answer with no checkout id in it: Daraja's error body,
     * NCBA's StatusCode 1, Co-op's {"CoopRefused": true}. NOT "no answer" --
     * the stored body is `null` then (a timeout, or Co-op's record written
     * before the bank was called) and the prompt may well be on the handset,
     * which is the distinction openPush and CoopStkSettlement::wasRefused draw.
     * Older rows kept the id only inside the body, hence the second check.
     */
    public static function wasRefused(MpesaStkCallback $push): bool
    {
        if ($push->checkout_request_id !== null) {
            return false;
        }

        $said = json_decode((string) $push->callback, true);

        return is_array($said) && ! array_key_exists('CheckoutRequestID', $said);
    }

    /** Where a push stands on its own, before its booking or fare has a say. */
    public static function ofPush(MpesaStkCallback $push, CarbonInterface $now): PaymentState
    {
        $attempt = (int) $push->id;

        if ($push->cancelled_at !== null) {
            return new PaymentState(self::CANCELLED, self::MSG_CANCELLED_IN_APP, $push->cancelled_at, $attempt);
        }
        if ($push->processed_at !== null) {
            return new PaymentState(self::FAILED, self::messageForResult(
                $push->result_code === null ? null : (int) $push->result_code,
                $push->result_desc,
            ), $push->processed_at, $attempt);
        }
        if (self::wasRefused($push)) {
            return new PaymentState(self::FAILED, self::MSG_NOT_SENT, $push->created_at, $attempt);
        }
        if ($push->unconfirmed_at !== null) {
            return new PaymentState(self::FAILED, self::MSG_UNCONFIRMED, $push->unconfirmed_at, $attempt);
        }
        if ($push->created_at === null || $push->created_at->lte(self::lookbackEdge($now))) {
            return new PaymentState(self::FAILED, self::MSG_UNCONFIRMED, $push->created_at?->copy()->addSeconds(self::UNCONFIRMED_AFTER_SECONDS), $attempt);
        }

        return new PaymentState(self::PENDING, null, null, $attempt);
    }

    /**
     * A QR fare: the relations latestStkPush and mpesa_qrcode_payment are read,
     * so load them first (PassengerPaymentFeed does).
     */
    public static function ofQrFare(QrcodePayment $fare, CarbonInterface $now): PaymentState
    {
        if ((bool) $fare->status) {
            return new PaymentState(self::PAID, null, $fare->mpesa_qrcode_payment?->created_at ?? $fare->created_at);
        }

        $push = $fare->latestStkPush;
        if ($push !== null) {
            return self::ofPush($push, $now);
        }

        if ($fare->created_at !== null && $fare->created_at->gt(self::inFlightEdge($now))) {
            return new PaymentState(self::PENDING);
        }

        // A fare row with no prompt behind it: the push request failed before
        // anything was recorded (Co-op without a token answers "not responding"
        // after the fare is written), or a legacy import. No money moved.
        return new PaymentState(self::FAILED, self::MSG_NOT_SENT, $fare->created_at);
    }

    /**
     * A booking: reads latestStkPush, loyaltyEntries and mpesa_booking_callbacks.
     *
     * The push decides an unpaid booking, with two exceptions. A push still
     * open outranks everything -- the sweeps cancel an unpaid booking while its
     * prompt can still be paid, and money that lands then is the passenger's
     * (it is refunded, see settledBecauseTripIsOver). And a booking the
     * passenger cancelled reads as cancelled whatever its last prompt said.
     * A booking that never had a push reads from the booking itself.
     */
    public static function ofBooking(Booking $booking, CarbonInterface $now): PaymentState
    {
        if ((bool) $booking->paid) {
            $paidAt = self::bookingPaidAt($booking);

            if (self::refundOf($booking) !== null) {
                return new PaymentState(self::REFUNDED, match ($booking->cancellation_reason) {
                    BookingCancellationReason::NoShow => self::MSG_REFUNDED_NO_SHOW,
                    BookingCancellationReason::TripOver => self::MSG_REFUNDED_TRIP_OVER,
                    default => self::MSG_REFUNDED,
                }, $paidAt);
            }

            return new PaymentState(self::PAID, null, $paidAt);
        }

        $push = $booking->latestStkPush;
        $pushState = $push === null ? null : self::ofPush($push, $now);

        if ($pushState?->status === self::PENDING) {
            return $pushState;
        }

        if (! (bool) $booking->status && self::cancelledByChoice($booking)) {
            return new PaymentState(self::CANCELLED, self::MSG_BOOKING_CANCELLED, $booking->cancelled_at ?? $booking->updated_at, (int) ($push?->id ?? 0));
        }

        if ($pushState !== null) {
            return $pushState;
        }

        if ((bool) $booking->status) {
            return new PaymentState(self::PENDING);
        }

        return new PaymentState(
            self::FAILED,
            $booking->cancellation_reason === BookingCancellationReason::Expired ? self::MSG_BOOKING_EXPIRED : self::MSG_TRIP_ENDED_UNPAID,
            $booking->cancelled_at ?? $booking->updated_at,
        );
    }

    /** The loyalty row that gave this booking's fare back, if one did. */
    public static function refundOf(Booking $booking): ?object
    {
        return $booking->loyaltyEntries->first(fn ($e) => self::typeOf($e) === LoyaltyTransactionType::Refunded->value);
    }

    /** What a points-paid booking spent, from its Redeemed row. */
    public static function pointsSpentOn(Booking $booking): ?float
    {
        $row = $booking->loyaltyEntries->first(fn ($e) => self::typeOf($e) === LoyaltyTransactionType::Redeemed->value);

        return $row === null ? null : round(abs((float) $row->value), 2);
    }

    private static function bookingPaidAt(Booking $booking): ?CarbonInterface
    {
        $receipt = $booking->mpesa_booking_callbacks->sortBy('id')->first();
        if ($receipt?->created_at !== null) {
            return $receipt->created_at;
        }

        $redeemed = $booking->loyaltyEntries->first(fn ($e) => self::typeOf($e) === LoyaltyTransactionType::Redeemed->value);

        return $redeemed?->created_at ?? $booking->updated_at;
    }

    /**
     * Cancelled on purpose rather than by a sweep or a trip ending. Rows from
     * before cancellation_reason existed carry none; a model save that flips
     * status stamps `cancelled` (Booking::booted), so null reads the same way.
     */
    private static function cancelledByChoice(Booking $booking): bool
    {
        return $booking->cancellation_reason === null
            || $booking->cancellation_reason === BookingCancellationReason::Cancelled;
    }

    private static function typeOf(object $entry): string
    {
        $type = $entry->type;

        return $type instanceof \BackedEnum ? (string) $type->value : (string) $type;
    }

    private static function lookbackEdge(CarbonInterface $now): CarbonInterface
    {
        return $now->copy()->subSeconds(self::SWEEP_LOOKBACK_SECONDS);
    }

    private static function inFlightEdge(CarbonInterface $now): CarbonInterface
    {
        return $now->copy()->subSeconds(self::PUSH_IN_FLIGHT_SECONDS);
    }

    // ------------------------------------------------------------------
    // The same reading, as SQL, for ?status=. The owner table is aliased `o`
    // and its latest push is joined as `lp` (latestPushJoin). Each clause
    // below mirrors one branch of ofPush/ofQrFare/ofBooking above.
    // ------------------------------------------------------------------

    /**
     * The owner's latest push, one row or none, for a LEFT JOIN LATERAL. Served
     * by the (owner, id) indexes from 2026_11_27_130100.
     */
    public static function latestPushJoin(string $ownerColumn): Builder
    {
        return DB::table('mpesa_stk_callbacks as p')
            ->whereColumn('p.'.$ownerColumn, 'o.id')
            ->orderByDesc('p.id')
            ->limit(1);
    }

    /** Narrow a QR-fare key query (`o` = qrcode_payments) to one status. */
    public static function whereQrFareIs(Builder $query, string $status, CarbonInterface $now): void
    {
        $inFlight = self::sqlTime(self::inFlightEdge($now));

        match ($status) {
            self::PAID => $query->whereRaw('o.status = true'),
            self::REFUNDED => $query->whereRaw('1 = 0'),
            self::PENDING => $query->whereRaw(
                'o.status = false AND ('.self::PUSH_PENDING." OR (lp.id IS NULL AND COALESCE(o.created_at, CAST('-infinity' AS timestamp)) > ?))",
                [self::sqlTime(self::lookbackEdge($now)), $inFlight],
            ),
            self::FAILED => $query->whereRaw(
                'o.status = false AND ('.self::PUSH_FAILED." OR (lp.id IS NULL AND COALESCE(o.created_at, CAST('-infinity' AS timestamp)) <= ?))",
                [self::sqlTime(self::lookbackEdge($now)), $inFlight],
            ),
            self::CANCELLED => $query->whereRaw('o.status = false AND '.self::PUSH_CANCELLED),
        };
    }

    /** Narrow a booking key query (`o` = bookings) to one status. */
    public static function whereBookingIs(Builder $query, string $status, CarbonInterface $now): void
    {
        $edge = self::sqlTime(self::lookbackEdge($now));
        $refunded = "EXISTS (SELECT 1 FROM loyalty_transactions r WHERE r.booking_id = o.id AND r.type = '"
            .LoyaltyTransactionType::Refunded->value."')";
        $byChoice = "(o.status = false AND (o.cancellation_reason IS NULL OR o.cancellation_reason = '"
            .BookingCancellationReason::Cancelled->value."'))";

        match ($status) {
            self::PAID => $query->whereRaw("o.paid = true AND NOT {$refunded}"),
            self::REFUNDED => $query->whereRaw("o.paid = true AND {$refunded}"),
            self::PENDING => $query->whereRaw(
                'o.paid = false AND ('.self::PUSH_PENDING.' OR (lp.id IS NULL AND o.status = true))',
                [$edge],
            ),
            self::CANCELLED => $query->whereRaw(
                'o.paid = false AND NOT '.self::PUSH_PENDING." AND ({$byChoice} OR ".self::PUSH_CANCELLED.')',
                [$edge],
            ),
            self::FAILED => $query->whereRaw(
                'o.paid = false AND NOT '.self::PUSH_PENDING." AND NOT {$byChoice} AND ("
                    .self::PUSH_FAILED.' OR (lp.id IS NULL AND o.status = false))',
                [$edge, $edge],
            ),
        };
    }

    /** wasRefused(), as SQL. */
    private const PUSH_REFUSED = "(lp.checkout_request_id IS NULL AND lp.callback NOT IN ('null', '') AND lp.callback NOT LIKE '%\"CheckoutRequestID\"%')";

    /** ofPush() === pending. One binding: the lookback edge. */
    private const PUSH_PENDING = '(lp.id IS NOT NULL AND lp.cancelled_at IS NULL AND lp.processed_at IS NULL'
        .' AND NOT '.self::PUSH_REFUSED.' AND lp.unconfirmed_at IS NULL'
        ." AND COALESCE(lp.created_at, CAST('-infinity' AS timestamp)) > ?)";

    /** ofPush() === failed. One binding: the lookback edge. */
    private const PUSH_FAILED = '(lp.id IS NOT NULL AND lp.cancelled_at IS NULL AND (lp.processed_at IS NOT NULL'
        .' OR '.self::PUSH_REFUSED.' OR lp.unconfirmed_at IS NOT NULL'
        ." OR COALESCE(lp.created_at, CAST('-infinity' AS timestamp)) <= ?))";

    /** ofPush() === cancelled. */
    private const PUSH_CANCELLED = '(lp.id IS NOT NULL AND lp.cancelled_at IS NOT NULL)';

    /** Timestamps are stored as UTC wall-clock, without a zone. */
    private static function sqlTime(CarbonInterface $at): string
    {
        return $at->copy()->setTimezone('UTC')->format('Y-m-d H:i:s');
    }
}
