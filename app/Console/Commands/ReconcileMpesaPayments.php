<?php

namespace App\Console\Commands;

use App\Http\Controllers\APIs\MpesaPaymentsController;
use App\Jobs\PollCoopStkPush;
use App\Models\Booking;
use App\Models\MpesaBookingCallback;
use App\Models\MpesaStkCallback;
use App\Models\QrcodePayment;
use App\Models\Vehicle;
use App\Services\Coop\CoopStkSettlement;
use App\Services\Mpesa\MpesaCredentialResolver;
use App\Services\Mpesa\StandInReceipt;
use App\Services\Ncba\NcbaStkSettlement;
use App\Services\Payments\PassengerPayments\PaymentStatus;
use App\Services\Super\Money\PaymentReconciliationAlerter;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The polling safety-net for STK payments.
 *
 * STK confirmation is primarily a webhook (App\Http\Controllers\APIs\MpesaPaymentsController@stkResponse),
 * but Daraja callbacks are routinely lost or delayed — and CheckPassengerPayments
 * then CANCELS the booking after 2 minutes, stranding a customer who actually paid.
 * This command queries Safaricom (STK Push Query) for pending pushes and CONFIRMS
 * the paid ones. Marking booking.paid fires the BookingPaid observer, so loyalty
 * and downstream side-effects run exactly as they would from the webhook.
 *
 * Scheduled every 2 minutes. Idempotent: a push is reconciled once (processed_at),
 * and the query is authoritative, so it can safely race the real callback.
 */
class ReconcileMpesaPayments extends Command
{
    protected $signature = 'payments:reconcile {--grace=45 : seconds to wait after a push before querying} {--window=1800 : how far back in seconds to reconcile}';

    protected $description = 'Query Daraja for pending STK payments whose callback was lost/delayed and confirm the paid ones';

    /**
     * The query answers that END a push as failed: insufficient funds,
     * cancelled on the phone, wrong PIN. Safaricom has decided these and no
     * callback will say otherwise.
     *
     * Nothing else closes it, however long it has been open. 4999 ("still
     * under processing"), 1037 and the rest are Safaricom not knowing yet --
     * and it answers those most during its own incidents, which is exactly
     * when callbacks run late. Closing on them stamped processed_at, and the
     * late "paid" callback was then acked "Already processed" and thrown
     * away: money on the till, booking or fare unpaid. Past the cut-off the
     * passenger is told "did not confirm" by giveUpOnSilentPushes instead,
     * which leaves the nonce open for that callback.
     */
    private const FINAL_FAILURE_CODES = ['1', '1032', '2001'];

    /** Pushes stamped unconfirmed per run; the rest wait for the next one. */
    public const SWEEP_BATCH = 500;

    public function handle(): int
    {
        $grace = (int) $this->option('grace');
        $window = (int) $this->option('window');
        $now = now();

        $pending = MpesaStkCallback::query()
            ->whereNull('processed_at')
            // Daraja pushes only; NCBA's and Co-op's are settled by asking the bank, below.
            ->whereNull('provider')
            // Bookings AND QR fares. A QR fare's lost callback used to be
            // nobody's job: the fare sat unpaid while the money was on the
            // till, and the passenger's Payments tab said "waiting" forever.
            ->where(fn ($q) => $q->where('booking_id', '>', 0)->orWhere('qrcode_payment_id', '>', 0))
            ->where('created_at', '<=', $now->copy()->subSeconds($grace))
            ->where('created_at', '>=', $now->copy()->subSeconds($window))
            ->get();

        $confirmed = 0;
        $failed = 0;
        $unknown = 0;

        // Best-effort in CLI: the poller has no request brand, so the aggregate
        // groups under whatever brand context (if any) the run carries.
        $brand = Context::has('brand') ? (string) Context::get('brand') : null;
        $alerter = app(PaymentReconciliationAlerter::class);

        foreach ($pending as $rec) {
            if ((int) $rec->booking_id <= 0) {
                match ($this->reconcileQrFare($rec, $now, $brand, $alerter)) {
                    'confirmed' => $confirmed++,
                    'failed' => $failed++,
                    default => $unknown++,
                };

                continue;
            }

            try {
                $booking = Booking::find((int) $rec->booking_id);

                // Booking gone but a push referenced it → an unmatched payment.
                if (! $booking) {
                    $alerter->record($brand, (string) $rec->booking_id, 0.0);
                    $rec->forceFill(['processed_at' => $now])->save();

                    continue;
                }

                // Already paid (webhook won the race) → close out.
                if ($booking->paid) {
                    $rec->forceFill(['processed_at' => $now])->save();

                    continue;
                }

                $checkoutId = $this->checkoutId($rec);
                $client = $checkoutId ? MpesaCredentialResolver::forBooking($booking) : null;
                if (! $checkoutId || ! $client) {
                    continue; // can't query — leave for a human / next run
                }

                $result = $client->stkQuery($checkoutId);
                if ($result === null) {
                    $unknown++;

                    continue; // transient (network / still processing) — retry next run
                }

                $code = (string) ($result['ResultCode'] ?? 'x');
                if ($code === '0') {
                    $this->confirmBooking($booking, $rec, $result);
                    $confirmed++;
                } elseif ($this->isFinalFailure($code)) {
                    $this->recordFailure($rec, $result, $booking->queue?->vehicle?->brand);
                    $failed++;
                } else {
                    // 4999 / 1037 and friends: Safaricom cannot say yet -- ask
                    // again next run (see FINAL_FAILURE_CODES).
                    $unknown++;
                }
            } catch (\Throwable $e) {
                Log::error('mpesa reconcile error', ['stk_id' => $rec->id, 'error' => $e->getMessage()]);
                $alerter->record($brand, (string) $rec->id, 0.0);
            }
        }

        $this->info("reconcile: confirmed=$confirmed failed=$failed unknown=$unknown scanned={$pending->count()}");

        $this->reconcileNcba($now, $grace, $window);
        $this->reconcileCoop($now, $grace, $window);
        $this->giveUpOnSilentPushes($now);

        return self::SUCCESS;
    }

    /**
     * A Daraja push for a QR fare: the same question as for a booking, settled
     * through applyStkResult -- the path a callback takes -- so a recovered
     * fare is receipted, rewarded and announced exactly as a called-back one.
     */
    private function reconcileQrFare(MpesaStkCallback $rec, Carbon $now, ?string $brand, PaymentReconciliationAlerter $alerter): string
    {
        try {
            $fare = QrcodePayment::withoutGlobalScopes()->find((int) $rec->qrcode_payment_id);
            if ($fare === null) {
                $alerter->record($brand, 'qr '.$rec->qrcode_payment_id, 0.0);
                $rec->forceFill(['processed_at' => $now])->save();

                return 'unknown';
            }

            // Already paid (the callback won the race) -> close out.
            if ((bool) $fare->status) {
                $rec->forceFill(['processed_at' => $now])->save();

                return 'confirmed';
            }

            $vehicle = Vehicle::withoutGlobalScopes()->find($fare->vehicle_id);
            $checkoutId = $this->checkoutId($rec);
            $client = $checkoutId && $vehicle ? MpesaCredentialResolver::forVehicle($vehicle) : null;
            if (! $checkoutId || ! $client) {
                return 'unknown';
            }

            $result = $client->stkQuery($checkoutId);
            if ($result === null) {
                return 'unknown';
            }

            $code = (string) ($result['ResultCode'] ?? 'x');
            if ($code === '0') {
                // The query can take 20 s, and the passenger may have entered
                // the PIN just before it: the real callback can land while we
                // wait and settle the push itself, with the real receipt. $rec
                // was read before all that, so the push is re-read under a lock
                // and left alone if it has been settled meanwhile.
                // applyStkResult's own guard on the fare stands behind this.
                $applied = DB::transaction(function () use ($rec, $vehicle, $fare, $result, $checkoutId): bool {
                    $push = MpesaStkCallback::whereKey($rec->id)->lockForUpdate()->first();
                    if ($push === null || $push->isProcessed()) {
                        return false;
                    }

                    // STK Push Query carries no receipt; the CheckoutRequestID is
                    // unique per push and satisfies the unique transid, as for a
                    // booking. The passenger's list shows no code rather than it.
                    app(MpesaPaymentsController::class)->applyStkResult($push, $vehicle->brand, 0, (string) ($result['ResultDesc'] ?? ''), [
                        'amount' => (float) $fare->amount,
                        'transid' => (string) ($result['MpesaReceiptNumber'] ?? $checkoutId),
                        'transdate' => Carbon::now('Africa/Nairobi')->format('Y-m-d H:i:s'),
                        'phone' => (string) ($push->phone ?? ''),
                    ], ['source' => 'reconcile', 'result' => $result]);

                    return true;
                });

                if ($applied) {
                    Log::info('mpesa reconcile confirmed a paid QR fare a lost callback missed', ['qrcode_payment_id' => $fare->id]);
                }

                return 'confirmed';
            }

            if ($this->isFinalFailure($code)) {
                $this->recordFailure($rec, $result, $vehicle->brand);

                return 'failed';
            }
        } catch (\Throwable $e) {
            Log::error('mpesa reconcile error', ['stk_id' => $rec->id, 'error' => $e->getMessage()]);
            $alerter->record($brand, (string) $rec->id, 0.0);
        }

        return 'unknown';
    }

    /** Whether Safaricom's answer to the query closes the push as failed. */
    private function isFinalFailure(string $code): bool
    {
        return in_array($code, self::FINAL_FAILURE_CODES, true);
    }

    /**
     * Close a push as failed WITH what Safaricom said, through the same write
     * a failure callback makes. It used to stamp processed_at alone, and the
     * passenger was told "failed" with no reason when the reason was known.
     */
    private function recordFailure(MpesaStkCallback $rec, array $result, ?string $brand): void
    {
        app(MpesaPaymentsController::class)->applyStkResult(
            $rec,
            $brand,
            is_numeric($result['ResultCode'] ?? null) ? (int) $result['ResultCode'] : null,
            isset($result['ResultDesc']) ? (string) $result['ResultDesc'] : null,
            null,
            ['source' => 'reconcile', 'result' => $result],
        );
    }

    /**
     * NOTHING STAYS PENDING FOREVER. A push nobody has answered ten minutes
     * after it was raised -- no callback, a query that cannot say, a bank that
     * never decides -- is stamped unconfirmed_at, and the passenger reads
     * "M-Pesa did not confirm this payment" instead of a spinner.
     *
     * NOT processed_at. The nonce stays open and every pass above keeps asking
     * for the rest of its window, so a late callback or a late "paid" from the
     * query still settles it -- the fare flips to paid and the phone is told
     * again. Cancelled pushes already read as cancelled, and refused ones never
     * put a prompt out; neither is touched.
     *
     * Bounded to the last day (PaymentStatus::SWEEP_LOOKBACK_SECONDS): older
     * pushes read as unconfirmed without a stamp, and stamping the backlog
     * would announce a backlog of old failures to people's phones. Through the
     * model, one row at a time, because the save is what announces it.
     *
     * Refused pushes are left out IN THE QUERY, before the LIMIT. Nothing ever
     * stamps one (no prompt went out, so there is nothing to settle), and
     * dropping them after the fetch let a day's worth of them -- bad numbers,
     * a Daraja outage -- fill every batch with rows that were never stamped,
     * so the real silent pushes behind them stayed "pending" all day.
     */
    private function giveUpOnSilentPushes(Carbon $now): void
    {
        $silent = MpesaStkCallback::query()
            ->whereNull('processed_at')
            ->whereNull('cancelled_at')
            ->whereNull('unconfirmed_at')
            ->where('created_at', '<=', $now->copy()->subSeconds(PaymentStatus::UNCONFIRMED_AFTER_SECONDS))
            ->where('created_at', '>', $now->copy()->subSeconds(PaymentStatus::SWEEP_LOOKBACK_SECONDS))
            ->where(fn ($q) => $q->where('booking_id', '>', 0)->orWhere('qrcode_payment_id', '>', 0))
            ->where(fn ($q) => PaymentStatus::wherePushNotRefused($q))
            ->orderBy('id')
            ->limit(self::SWEEP_BATCH)
            ->get();

        foreach ($silent as $push) {
            try {
                $push->forceFill(['unconfirmed_at' => $now])->save();
            } catch (\Throwable $e) {
                report($e);
            }
        }

        $this->info("reconcile: unconfirmed={$silent->count()}");
    }

    /**
     * NCBA Till STK pushes still open after PollNcbaStkPush gave up on them.
     *
     * NCBA has no callback at all, so for these the query is not a safety net
     * but the only way the outcome is ever learned: bookings and QR payments
     * alike, settled through the same path as every other push.
     */
    private function reconcileNcba(Carbon $now, int $grace, int $window): void
    {
        $pending = MpesaStkCallback::query()
            ->whereNull('processed_at')
            ->where('provider', MpesaStkCallback::PROVIDER_NCBA)
            ->whereNotNull('checkout_request_id')
            ->where('created_at', '<=', $now->copy()->subSeconds($grace))
            ->where('created_at', '>=', $now->copy()->subSeconds($window))
            ->get();

        $counts = [];
        foreach ($pending as $push) {
            try {
                $outcome = app(NcbaStkSettlement::class)->check($push);
            } catch (\Throwable $e) {
                Log::error('ncba stk reconcile error', ['stk_id' => $push->id, 'error' => $e->getMessage()]);
                $outcome = 'error';
            }
            $counts[$outcome] = ($counts[$outcome] ?? 0) + 1;
        }

        $this->info('reconcile ncba: '.json_encode($counts)." scanned={$pending->count()}");
    }

    /**
     * Co-op pushes still open after PollCoopStkPush gave up on them.
     *
     * Bookings and QR payments alike. A push whose own request got no answer
     * (no checkout id yet) is asked about too -- the bank may have taken it.
     * Each is handed to the queue as one check rather than asked here: a bank
     * that hangs must not hold up this command's Daraja and NCBA passes.
     */
    private function reconcileCoop(Carbon $now, int $grace, int $window): void
    {
        $pending = MpesaStkCallback::query()
            ->whereNull('processed_at')
            ->where('provider', MpesaStkCallback::PROVIDER_COOP)
            ->whereNotNull('callback_nonce')
            ->where('created_at', '<=', $now->copy()->subSeconds($grace))
            ->where('created_at', '>=', $now->copy()->subSeconds($window))
            ->get()
            ->reject(fn (MpesaStkCallback $push) => CoopStkSettlement::wasRefused($push));

        foreach ($pending as $push) {
            PollCoopStkPush::dispatch($push->id, PollCoopStkPush::ONE_OFF);
        }

        $this->info("reconcile coop: queued={$pending->count()}");
    }

    private function checkoutId(MpesaStkCallback $rec): ?string
    {
        $push = json_decode((string) $rec->callback, true);

        return is_array($push) ? ($push['CheckoutRequestID'] ?? null) : null;
    }

    /**
     * Mark the booking paid and record the reconciliation. STK Push Query does not
     * return the M-Pesa receipt (only the callback does), so transid falls back to
     * the CheckoutRequestID — enough to reconcile; Transaction Status can enrich later.
     */
    private function confirmBooking(Booking $booking, MpesaStkCallback $rec, array $result): void
    {
        $applied = DB::transaction(function () use ($booking, $rec, $result): bool {
            // Re-read under locks: the real callback may have settled this
            // push, or another prompt the booking, during the query.
            // $booking and $rec were read before it and would write a
            // second receipt for one payment.
            $rec = MpesaStkCallback::whereKey($rec->id)->lockForUpdate()->first();
            if ($rec === null || $rec->isProcessed()) {
                return false;
            }
            $booking = Booking::whereKey($booking->id)->lockForUpdate()->first() ?? $booking;
            if ((bool) $booking->paid) {
                $rec->forceFill(['processed_at' => now()])->save();

                return false;
            }

            $booking->paid = true;   // fires BookingPaid → loyalty etc.
            $booking->save();

            $cb = new MpesaBookingCallback;
            // STK Query has no receipt; the CheckoutRequestID is unique per push
            // and satisfies the unique transid. phone is NOT NULL → take the booking's.
            $cb->transid = (string) ($result['MpesaReceiptNumber'] ?? $this->checkoutId($rec) ?? StandInReceipt::forReconcile((int) $rec->id));
            $cb->booking_id = $booking->id;
            $cb->phone = (string) ($booking->phone ?? '');
            $cb->amount = (float) ($booking->amount ?? 0);
            $cb->transdate = now();
            $cb->callback = json_encode(['source' => 'reconcile', 'result' => $result]);
            $cb->save();

            $rec->forceFill(['processed_at' => now()])->save();

            return true;
        });

        if ($applied) {
            Log::info('mpesa reconcile confirmed a paid booking a lost callback missed', ['booking_id' => $booking->id]);
        }
    }
}
