<?php

declare(strict_types=1);

namespace App\Services\Payments\PassengerPayments;

use App\Enums\NotificationType;
use App\Enums\UserType;
use App\Events\PaymentUpdated;
use App\Models\MpesaStkCallback;
use App\Models\User;
use App\Services\Notifications\NotificationService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Tells a passenger's phone that one of their in-app payments changed status:
 * payment.updated on their private channel, and for an M-Pesa QR fare a push
 * as well ("Paid KES 100 to KDN 458N · UJ6BJ9BXGZ").
 *
 * CALLED FROM THE PATHS THAT ALREADY MOVE THE STATUS, not beside them: the
 * booking lifecycle events (BookingCreated, BookingPaid, BookingCancelled --
 * which every refund and both sweeps already dispatch), and the model events
 * of the rows that decide everything else (a push, a QR fare, a receipt). A
 * caller only says WHICH payment may have moved. Whether it did is read here,
 * fresh, through PaymentStatus -- so a resent Safaricom callback, a second
 * poller settling the same push, and the three saves one settlement makes all
 * reach the same answer and only the first is announced.
 *
 * EXACTLY ONCE PER CHANGE, IN ORDER. The status last announced is kept on the
 * owner row (payment_announced). The owner row is locked, the payment read
 * under that lock, and payment_announced moved if the reading is new -- so
 * two announcers of one payment take turns, and each reads what the other
 * left. Row-level, so it holds across both app hosts and both workers, and
 * under whichever brand a callback or a sweep happens to run.
 *
 * PASSENGERS ONLY. A booking's user_id is whoever keyed it, and a conductor's
 * walk-ins carry the conductor's id: their channel is not told about a
 * stranger's fare (PassengerPaymentFeedController draws the same line).
 *
 * AFTER COMMIT, AND NEVER IN THE WAY. Work is deferred until the surrounding
 * transaction commits (DB::afterCommit runs it at once when there is none), so
 * a rolled-back settlement announces nothing. Every failure is reported and
 * swallowed: this runs inside the M-Pesa callback, and a socket or push
 * failure must never turn a payment that went through into a 500 Safaricom
 * retries.
 */
final class PaymentUpdates
{
    public function __construct(
        private readonly PassengerPaymentFeed $feed,
        private readonly NotificationService $notifications,
    ) {}

    public static function bookingChanged(int $bookingId): void
    {
        self::afterCommit(PassengerPayment::KIND_BOOKING, $bookingId);
    }

    public static function qrFareChanged(int $fareId): void
    {
        self::afterCommit(PassengerPayment::KIND_QR_FARE, $fareId);
    }

    /** A push belongs to a booking or to a QR fare; a wallet top-up's belongs to neither. */
    public static function pushChanged(MpesaStkCallback $push): void
    {
        if ((int) $push->booking_id > 0) {
            self::bookingChanged((int) $push->booking_id);
        } elseif ((int) $push->qrcode_payment_id > 0) {
            self::qrFareChanged((int) $push->qrcode_payment_id);
        }
    }

    private static function afterCommit(string $kind, int $id): void
    {
        if ($id <= 0) {
            return;
        }

        try {
            DB::afterCommit(static fn () => app(self::class)->announce($kind, $id));
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * Read the payment as it now stands and, if the phone has not been told
     * this yet, tell it. Public for the tests and for a hand re-run; callers
     * in the app go through the static entry points above.
     */
    public function announce(string $kind, int $id): void
    {
        try {
            $owner = $this->owner($kind, $id);
            if ($owner === null) {
                return;
            }

            $payment = $this->claim($kind, $id);
            if ($payment === null) {
                return;
            }
        } catch (Throwable $e) {
            report($e);

            return;
        }

        // Guarded apart: the socket frame and the push are independent, and
        // one failing must not cost the passenger the other.
        try {
            $row = $payment->toArray();
            event(new PaymentUpdated(
                userId: (int) $owner->id,
                paymentId: (string) $row['id'],
                kind: $payment->kind,
                status: $payment->state->status,
                updatedAt: $row['updated_at'],
            ));
        } catch (Throwable $e) {
            report($e);
        }

        try {
            $this->push($payment, $owner);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /** The payer, when the payment has one and it is a passenger account. */
    private function owner(string $kind, int $id): ?User
    {
        $userId = DB::table($kind === PassengerPayment::KIND_BOOKING ? 'bookings' : 'qrcode_payments')
            ->where('id', $id)
            ->value('user_id');
        if ($userId === null) {
            return null;
        }

        $user = User::withoutGlobalScopes()->find((int) $userId);

        return $user !== null && $user->type === UserType::Passenger ? $user : null;
    }

    /**
     * Read the payment and, if the phone has not been told this yet, move
     * payment_announced to it. The payment for the one caller that moved it.
     *
     * READ UNDER THE OWNER ROW'S LOCK. An unlocked read-then-compare let a
     * stale reader win: the sweep read a fare "failed" just as a late
     * callback paid it; the callback announced "paid", and then the sweep's
     * "failed" -- different from "paid" -- was announced after it, with a
     * false "Payment failed" push. Under the lock the second announcer reads
     * after the first has finished, so it reads what is true now. The lock
     * is held for one read and one update; the socket frame and the push go
     * out after it is released.
     */
    private function claim(string $kind, int $id): ?PassengerPayment
    {
        $table = $kind === PassengerPayment::KIND_BOOKING ? 'bookings' : 'qrcode_payments';

        return DB::transaction(function () use ($table, $kind, $id): ?PassengerPayment {
            $announced = DB::table($table)->where('id', $id)->lockForUpdate()->first(['payment_announced']);
            if ($announced === null) {
                return null;
            }

            $payment = $this->feed->find($kind, $id, null, withRoute: false);
            if ($payment === null || ! $payment->isAnnounceable()) {
                return null;
            }

            $fingerprint = $payment->state->fingerprint();
            if ($announced->payment_announced === $fingerprint) {
                return null;
            }

            // Query builder, not a model save: no model events (this is called
            // FROM them), and updated_at is left alone -- it is the passenger's
            // payment that moved, not this bookkeeping.
            DB::table($table)->where('id', $id)->update(['payment_announced' => $fingerprint]);

            return $payment;
        });
    }

    /**
     * The fare push, for an M-Pesa QR fare that settled one way or the other.
     *
     * M-Pesa only: that is the fare whose outcome arrives after the passenger
     * has left the screen -- they entered a PIN on another prompt and Safaricom
     * answers in its own time. A points or wallet fare is decided at the tap and
     * shown there. Bookings already get "Booking confirmed" (NotifyBookingConfirmed).
     *
     * Only for a fare raised in the last day: nothing that re-announces old rows
     * (a hand re-run, a backfill) may buzz a phone about last month's ride.
     * Type `payment`, referenceId the payment id the app opens the receipt by.
     * NotificationService's own (referenceId, title) dedupe stands behind the
     * claim above.
     */
    private function push(PassengerPayment $payment, User $user): void
    {
        $row = $payment->toArray();
        $status = $payment->state->status;

        if ($payment->kind !== PassengerPayment::KIND_QR_FARE
            || $row['method'] !== PassengerPayment::METHOD_MPESA
            || ! in_array($status, [PaymentStatus::PAID, PaymentStatus::FAILED], true)
            || $row['created_at'] === null
            || Carbon::parse($row['created_at'])->lt(Carbon::now()->subSeconds(PaymentStatus::SWEEP_LOOKBACK_SECONDS))) {
            return;
        }

        $params = [
            'amount' => self::shillings((float) $row['amount']),
            'plate' => $row['plate'] ?? 'the matatu',
            'receipt' => (string) $row['mpesa_receipt'],
            'reason' => (string) $row['status_message'],
        ];

        if ($status === PaymentStatus::PAID) {
            $key = $row['mpesa_receipt'] !== null ? 'paid' : 'paid_no_receipt';
            $message = 'Paid KES '.$params['amount'].' to '.$params['plate'].($row['mpesa_receipt'] !== null ? ' · '.$row['mpesa_receipt'] : '');
            $title = 'Fare paid';
        } else {
            $key = 'failed';
            $message = 'KES '.$params['amount'].' to '.$params['plate'].' was not paid. '.$params['reason'];
            $title = 'Payment failed';
        }

        $this->notifications->dispatch(
            $user,
            NotificationType::Payment,
            $title,
            $message,
            (string) $row['id'],
            titleKey: 'notifications.qr_fare.'.$key.'.title',
            messageKey: 'notifications.qr_fare.'.$key.'.body',
            params: $params,
        );
    }

    /** 100 -> "100", 99.5 -> "99.50": fares are whole shillings almost always. */
    private static function shillings(float $amount): string
    {
        return floor($amount) === $amount ? number_format($amount, 0, '.', ',') : number_format($amount, 2, '.', ',');
    }
}
