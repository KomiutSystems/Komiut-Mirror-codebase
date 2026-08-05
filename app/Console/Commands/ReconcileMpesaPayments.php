<?php

namespace App\Console\Commands;

use App\Models\Booking;
use App\Models\MpesaBookingCallback;
use App\Models\MpesaStkCallback;
use App\Services\Mpesa\MpesaCredentialResolver;
use App\Services\Super\Money\PaymentReconciliationAlerter;
use Illuminate\Console\Command;
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

    public function handle(): int
    {
        $grace = (int) $this->option('grace');
        $window = (int) $this->option('window');
        $now = now();

        $pending = MpesaStkCallback::query()
            ->whereNull('processed_at')
            ->whereNotNull('booking_id')
            ->where('booking_id', '>', 0)
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
                } elseif (in_array($code, ['1', '1032', '2001'], true)) {
                    // Cancelled by user / failed — stop reconciling it.
                    $rec->forceFill(['processed_at' => $now])->save();
                    $failed++;
                } else {
                    // 1037 = "no response / still processing" and friends → retry later.
                    $unknown++;
                }
            } catch (\Throwable $e) {
                Log::error('mpesa reconcile error', ['stk_id' => $rec->id, 'error' => $e->getMessage()]);
                $alerter->record($brand, (string) $rec->id, 0.0);
            }
        }

        $this->info("reconcile: confirmed=$confirmed failed=$failed unknown=$unknown scanned={$pending->count()}");

        return self::SUCCESS;
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
        DB::transaction(function () use ($booking, $rec, $result) {
            $booking->paid = true;   // fires BookingPaid → loyalty etc.
            $booking->save();

            $cb = new MpesaBookingCallback;
            // STK Query has no receipt; the CheckoutRequestID is unique per push
            // and satisfies the unique transid. phone is NOT NULL → take the booking's.
            $cb->transid = (string) ($result['MpesaReceiptNumber'] ?? $this->checkoutId($rec) ?? ('recon-'.$rec->id));
            $cb->booking_id = $booking->id;
            $cb->phone = (string) ($booking->phone ?? '');
            $cb->amount = (float) ($booking->amount ?? 0);
            $cb->transdate = now();
            $cb->callback = json_encode(['source' => 'reconcile', 'result' => $result]);
            $cb->save();

            $rec->forceFill(['processed_at' => now()])->save();
        });

        Log::info('mpesa reconcile confirmed a paid booking a lost callback missed', ['booking_id' => $booking->id]);
    }
}
