<?php

declare(strict_types=1);

namespace App\Services\Wallet;

use App\Enums\PaymentMethod;
use App\Enums\WalletEntryKind;
use App\Events\FarePaidFromWallet;
use App\Events\PassengerBalanceChanged;
use App\Models\Booking;
use App\Models\QrcodePayment;
use App\Models\Queue;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\Booking\BookingCancellation;
use App\Support\Shillings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Paying a fare out of the wallet.
 *
 * MIRRORS LoyaltyService, guard for guard, and that is a deliberate choice
 * rather than laziness: those guards were each written after something went
 * wrong on a real bus, and a second money rail that re-derived them from
 * scratch would re-learn the same lessons at a passenger's expense.
 *
 *   payForBooking   <- redeemForBooking     payForVehicle  <- redeemForVehicle
 *   ----------------------------------      ----------------------------------
 *   not your booking           403          invalid / tampered QR      422
 *   already paid from wallet   REPLAY       sticker withdrawn          410
 *   already paid otherwise     422          recent identical tap       REPLAY
 *   reservation expired        422          insufficient balance       422
 *   trip already over          422
 *   insufficient balance       422
 *
 * TWO THINGS DIFFER FROM LOYALTY, both because this is real money.
 *
 * 1. A wallet fare writes a `transactions` row against the bus, so it reaches
 *    the crew's takings and the owner reports. A points ride writes none,
 *    because nothing was earned; a wallet ride earns the SACCO its fare.
 *
 * 2. That fare is NOT counted as collected. It is in the Komiut float, not the
 *    SACCO's till, until it is settled -- so `summaries.wallet_amount` and
 *    BookingSettlement's `amount_pending_settlement` carry it, and
 *    `mpesa_amount` / `amount_collected` deliberately do not. An operator
 *    reconciling their bank against mpesa_amount must still balance.
 *
 * THE PIN IS CHECKED BEFORE THE MONEY TRANSACTION OPENS, never inside it. See
 * WalletService::assertPinMatches: a wrong PIN has to be counted by the rate
 * limiter, and a counter written inside a transaction that the refusal rolls
 * back forgets every failure.
 */
final class WalletPaymentService
{
    /** How long after a tap an identical one is treated as the same ride. */
    private const SCAN_REPLAY_MINUTES = 3;

    public function __construct(private WalletService $wallet) {}

    /**
     * Pay for a booking from the wallet.
     *
     * @return array{ok: bool, status?: int, error?: string, code?: string, extra?: array<string, mixed>, booking?: Booking, entry?: WalletTransaction, replayed?: bool}
     */
    public function payForBooking(User $user, Booking $booking, ?string $pin): array
    {
        if ((int) $booking->user_id !== (int) $user->id) {
            return ['ok' => false, 'status' => 403, 'error' => 'This booking is not yours.'];
        }

        $amountCents = Shillings::toCents((int) round((float) $booking->amount));

        if ($amountCents <= 0) {
            return ['ok' => false, 'status' => 422, 'error' => 'This booking has no fare to pay.'];
        }

        /**
         * A REPLAY IS A SUCCESS, NOT A CONFLICT -- lifted wholesale from
         * redeemForBooking, whose comment explains why: the app retries from a
         * handset on a moving matatu, so the request that settled the ride is
         * exactly the one whose response is most likely to be lost. Answering
         * the retry with "already paid" tells the passenger their paid ride
         * failed.
         *
         * Checked against the LEDGER, not against `paid`. The flag and the
         * ledger row are written in the same transaction and nothing ever sets
         * `paid` back to false, so a flag check always fires first and the
         * replay path would be unreachable -- the exact bug the loyalty
         * docblock records having had.
         */
        if ((bool) $booking->paid) {
            $existing = $this->fareEntryFor((int) $booking->id);

            if ($existing !== null) {
                return ['ok' => true, 'booking' => $booking, 'entry' => $existing, 'replayed' => true];
            }

            return ['ok' => false, 'status' => 422, 'error' => 'This booking is already paid.'];
        }

        $wallet = Wallet::where('user_id', $user->id)->first();

        if ($wallet === null || (int) $wallet->balance_cents < $amountCents) {
            return $this->refused(WalletRefusal::insufficientBalance((int) ($wallet->balance_cents ?? 0)));
        }

        if (! $wallet->canPay()) {
            return $this->refused(WalletRefusal::frozen());
        }

        try {
            $this->wallet->assertPinFor($wallet, $pin, $amountCents);
        } catch (WalletRefusal $refusal) {
            return $this->refused($refusal);
        }

        try {
            $result = DB::transaction(function () use ($user, $booking, $amountCents) {
                // THE BOOKING IS LOCKED, not just read. The crew can end the
                // trip, and a cancellation sweep can expire the hold, between
                // the passenger opening the sheet and tapping pay.
                $fresh = Booking::withoutGlobalScopes()->lockForUpdate()->find($booking->id);

                if ($fresh === null || ! (bool) $fresh->status) {
                    throw new WalletPaymentRefused(422, 'This reservation has expired. Please book again.');
                }

                if ((bool) $fresh->paid) {
                    // Someone else got there while we waited on the lock.
                    throw new WalletPaymentRefused(422, 'This booking is already paid.');
                }

                // A DEAD TRIP CANNOT BE PAID FOR. Money spent on a Completed or
                // Cancelled trip bought a ride nobody will take, and the one
                // settlement path for a paid unboarded booking is the crew's --
                // and the crew has gone home.
                $queue = Queue::withoutGlobalScopes()->find($fresh->queue_id);

                if (BookingCancellation::isTripOver($queue)) {
                    throw new WalletPaymentRefused(422, 'This trip has ended. Please book another.');
                }

                $vehicleId = $queue?->vehicle_id === null ? null : (int) $queue->vehicle_id;

                $entry = $this->wallet->debit(
                    (int) $user->id,
                    $amountCents,
                    WalletEntryKind::Fare,
                    'fare:booking:'.$fresh->id,
                    [
                        'title' => 'Fare',
                        'detail' => $this->bookingDetail($fresh),
                        'booking_id' => $fresh->id,
                        'vehicle_id' => $vehicleId,
                        'sacco_id' => $queue?->sacco_id,
                        // Stamped from the VEHICLE, not the wallet: settlement
                        // splits per bank and the bank owed for a ride is a
                        // property of the bus.
                        'financier' => $vehicleId === null ? null : Vehicle::withoutGlobalScopes()->whereKey($vehicleId)->value('financier'),
                    ],
                );

                $fresh->paid = true;
                $fresh->payment_method = PaymentMethod::Wallet;
                $fresh->save();

                $this->recordFareAgainstTheBus($entry, $vehicleId, (float) $fresh->amount, Carbon::now());

                return ['booking' => $fresh, 'entry' => $entry];
            });
        } catch (WalletPaymentRefused $refused) {
            return ['ok' => false, 'status' => $refused->status, 'error' => $refused->getMessage()];
        } catch (WalletRefusal $refusal) {
            return $this->refused($refusal);
        }

        $this->announce((int) $user->id, $result['entry']);

        return ['ok' => true, 'booking' => $result['booking'], 'entry' => $result['entry'], 'replayed' => false];
    }

    /**
     * Pay a fare by tapping the bus's QR, with no booking.
     *
     * @return array{ok: bool, status?: int, error?: string, code?: string, extra?: array<string, mixed>, payment?: QrcodePayment, entry?: WalletTransaction, replayed?: bool}
     */
    public function payForVehicle(
        User $user,
        Vehicle $vehicle,
        int $fareShillings,
        ?int $seatArrangementId,
        ?string $pin,
        ?string $idempotencyKey = null,
    ): array {
        if (! (bool) $vehicle->status) {
            // Mirrors stickerNoLongerValid: a withdrawn sticker is gone, not
            // broken, so 410 rather than 404.
            return [
                'ok' => false,
                'status' => 410,
                'error' => 'This QR code is no longer in use. Ask the conductor how to pay.',
                'code' => 'vehicle_inactive',
            ];
        }

        if ($fareShillings <= 0) {
            return ['ok' => false, 'status' => 422, 'error' => 'Enter the fare to pay.'];
        }

        $amountCents = Shillings::toCents($fareShillings);

        /**
         * THE REPLAY CHECK COMES FIRST -- BEFORE balance, frozen and PIN.
         *
         * This order is load-bearing and was wrong once. Consider a passenger
         * whose balance is exactly the fare: they tap, it succeeds, the
         * response is lost, the app retries. With the balance checked first the
         * retry sees a balance of zero and answers `insufficient_balance` --
         * telling somebody who has just paid that their ride failed, which is
         * the precise failure the replay exists to prevent. redeemForBooking
         * and redeemForVehicle both check replay first for this reason.
         */
        $recent = $this->recentTap((int) $user->id, (int) $vehicle->id, $fareShillings, $idempotencyKey);

        if ($recent !== null) {
            return ['ok' => true, 'payment' => $recent, 'entry' => $this->fareEntryForTap((int) $recent->id), 'replayed' => true];
        }

        $wallet = Wallet::where('user_id', $user->id)->first();

        if ($wallet === null || (int) $wallet->balance_cents < $amountCents) {
            return $this->refused(WalletRefusal::insufficientBalance((int) ($wallet->balance_cents ?? 0)));
        }

        if (! $wallet->canPay()) {
            return $this->refused(WalletRefusal::frozen());
        }

        try {
            $this->wallet->assertPinFor($wallet, $pin, $amountCents);
        } catch (WalletRefusal $refusal) {
            return $this->refused($refusal);
        }

        try {
            $result = DB::transaction(function () use ($user, $vehicle, $fareShillings, $amountCents, $seatArrangementId, $idempotencyKey) {
                // The wallet row lock serialises a passenger's concurrent taps,
                // so the replay check below sees the other one's receipt.
                $locked = $this->wallet->lock((int) $user->id);

                $again = $this->recentTap((int) $user->id, (int) $vehicle->id, $fareShillings, $idempotencyKey);

                if ($again !== null) {
                    return ['replay' => $again];
                }

                /**
                 * The receipt is written FIRST so the ledger row can key on its
                 * id, exactly as redeemForVehicle does. If the debit then fails
                 * the whole transaction rolls back and no orphan receipt
                 * survives claiming a ride nobody paid for.
                 *
                 * `amount` IS ZERO and that is the honest number, the same
                 * reasoning the points path uses: no shillings reached the
                 * SACCO's till. The money is real, but it is in the Komiut
                 * float until settlement. `fare` carries what the ride was
                 * worth and `payment_method` says how it was paid, so the
                 * receipt reads "KES 70 · wallet" without the KES ever being
                 * counted as collected.
                 */
                $payment = QrcodePayment::create([
                    'vehicle_id' => $vehicle->id,
                    'seat_arrangement_id' => $seatArrangementId,
                    'user_id' => $user->id,
                    'amount' => 0,
                    'fare' => $fareShillings,
                    'payment_method' => PaymentMethod::Wallet->value,
                    // Unique, so even a race past the checks above collides here
                    // rather than charging twice.
                    'idempotency_key' => $idempotencyKey,
                    'status' => true,
                ]);

                $entry = $this->wallet->debit(
                    (int) $user->id,
                    $amountCents,
                    WalletEntryKind::Fare,
                    'fare:tap:'.$payment->id,
                    [
                        'title' => 'Fare',
                        'detail' => trim((string) $vehicle->plate) === '' ? 'Fare' : 'Fare · '.$vehicle->plate,
                        'vehicle_id' => $vehicle->id,
                        'sacco_id' => $vehicle->sacco_id,
                        'financier' => $vehicle->financier,
                        'meta' => ['qrcode_payment_id' => $payment->id],
                    ],
                );

                $this->recordFareAgainstTheBus($entry, (int) $vehicle->id, (float) $fareShillings, Carbon::now());

                return ['payment' => $payment, 'entry' => $entry];
            });
        } catch (WalletRefusal $refusal) {
            // Thrown, not returned, so the receipt above rolls back with it --
            // the trap redeemForVehicle's comment calls out by name.
            return $this->refused($refusal);
        }

        if (isset($result['replay'])) {
            return [
                'ok' => true,
                'payment' => $result['replay'],
                'entry' => $this->fareEntryForTap((int) $result['replay']->id),
                'replayed' => true,
            ];
        }

        $this->announce((int) $user->id, $result['entry']);
        $this->tellTheCrew($result['payment'], $fareShillings, $user);

        return ['ok' => true, 'payment' => $result['payment'], 'entry' => $result['entry'], 'replayed' => false];
    }

    /**
     * Tell the conductor the fare was paid.
     *
     * A WALLET TAP LEAVES NOTHING AT THE DOOR -- no cash, no SMS, no till
     * confirmation -- so without this the conductor has only the passenger's
     * word and a phone screen that could be an old receipt. Exactly the
     * reasoning behind the crew announce in redeemForVehicle, and the same
     * guard: a socket failure must never fail a ride that is already paid for.
     */
    private function tellTheCrew(QrcodePayment $payment, int $fareShillings, User $user): void
    {
        try {
            FarePaidFromWallet::dispatch($payment, $fareShillings, $user->firstname ?? null);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * The fare as the bus's own money, so the crew and the owner can see it.
     *
     * `vehicle_id` null means a booking on no queue -- nothing to attribute it
     * to, and inventing a bus would be worse than leaving the row off. The
     * ledger still has the debit either way, so the passenger's money is never
     * unaccounted for.
     *
     * `summarized` is left FALSE so GenerateVehicleSummaries picks it up on its
     * next five-minute pass and rolls it into `summaries.wallet_amount`. Writing
     * the summary here would mean holding that row's lock -- shared by every
     * payment to that bus that day -- inside a passenger's request.
     */
    private function recordFareAgainstTheBus(WalletTransaction $entry, ?int $vehicleId, float $fare, Carbon $at): void
    {
        if ($vehicleId === null) {
            return;
        }

        Transaction::withoutGlobalScopes()->create([
            'vehicle_id' => $vehicleId,
            'wallet_transaction_id' => $entry->id,
            'amount' => $fare,
            'trans_date' => $at,
        ]);
    }

    /**
     * Tell the passenger's phone, after the money has committed.
     *
     * AFTER, never inside: a socket failure must not roll back a ride that has
     * already been paid for. PassengerBalanceChanged::announce swallows its own
     * failures for the same reason.
     *
     * BookingPaid is NOT dispatched from here -- Booking::updated fires it when
     * `paid` is saved, so the crew's "To collect" push and the loyalty earn
     * already happen. Dispatching again would double them.
     */
    private function announce(int $userId, WalletTransaction $entry): void
    {
        PassengerBalanceChanged::wallet(
            userId: $userId,
            balanceShillings: $entry->balanceAfterShillings(),
            deltaShillings: -$entry->amountShillings(),
            reason: WalletEntryKind::Fare->value,
        );
    }

    private function fareEntryFor(int $bookingId): ?WalletTransaction
    {
        return WalletTransaction::where('booking_id', $bookingId)
            ->where('kind', WalletEntryKind::Fare->value)
            ->first();
    }

    private function fareEntryForTap(int $qrcodePaymentId): ?WalletTransaction
    {
        return WalletTransaction::where('reference', 'fare:tap:'.$qrcodePaymentId)->first();
    }

    /**
     * A tap that is the SAME payment as one already made, not a new fare.
     *
     * KEYED ON THE CLIENT'S IDEMPOTENCY KEY WHEN THERE IS ONE. The app mints
     * one per payment (core/network/idempotency.dart) and pay() sends it, so a
     * retry carries the same key and a second genuine fare carries a new one.
     *
     * THE TIME WINDOW IS ONLY A FALLBACK, and it has to match on amount too.
     * recentScanRedemption uses a window alone, which is right for points --
     * nobody redeems twice in a row by accident. Real money breaks that:
     * paying for a companion is ordinary on a matatu, and a window-only rule
     * answers the second fare with the first receipt and `replayed: true`. The
     * passenger is never charged, the conductor sees a paid screen, and the
     * SACCO loses the fare.
     *
     * Either way the receipt must have a real fare behind it, since it is
     * written before the debit and the debit can still roll back.
     */
    private function recentTap(int $userId, int $vehicleId, int $fareShillings, ?string $idempotencyKey): ?QrcodePayment
    {
        $query = QrcodePayment::where('user_id', $userId)
            ->where('vehicle_id', $vehicleId)
            ->where('payment_method', PaymentMethod::Wallet->value);

        if ($idempotencyKey !== null && $idempotencyKey !== '') {
            $query->where('idempotency_key', $idempotencyKey);
        } else {
            $query->whereNull('idempotency_key')
                ->where('fare', $fareShillings)
                ->where('created_at', '>=', Carbon::now()->subMinutes(self::SCAN_REPLAY_MINUTES));
        }

        return $query
            // The receipt must have a real fare behind it. The receipt is
            // written BEFORE the debit, and the debit can still roll back, so a
            // receipt on its own proves nothing was paid. Matched on
            // `reference`, which is UNIQUE and therefore an index lookup --
            // rather than reading every row's `meta` into PHP.
            ->whereExists(fn ($q) => $q->selectRaw('1')
                ->from('wallet_transactions')
                ->whereRaw("wallet_transactions.reference = 'fare:tap:' || qrcode_payments.id"))
            ->latest('id')
            ->first();
    }

    /** @return array{ok: false, status: int, error: string, code: string, extra: array<string, mixed>} */
    private function refused(WalletRefusal $refusal): array
    {
        return [
            'ok' => false,
            'status' => $refusal->status,
            'error' => $refusal->getMessage(),
            'code' => $refusal->errorCode,
            'extra' => $refusal->extra,
        ];
    }

    private function bookingDetail(Booking $booking): string
    {
        $from = $booking->from?->name;
        $to = $booking->to?->name;

        if ($from === null || $to === null) {
            return 'Fare';
        }

        return 'Fare · '.$from.' to '.$to;
    }
}
