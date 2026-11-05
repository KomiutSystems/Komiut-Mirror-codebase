<?php

declare(strict_types=1);

namespace App\Services\Ncba;

use App\Http\Controllers\APIs\MpesaPaymentsController;
use App\Models\Booking;
use App\Models\MpesaBookingCallback;
use App\Models\MpesaQrcodePayment;
use App\Models\MpesaStkCallback;
use App\Models\QrcodePayment;
use App\Models\Vehicle;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Find out how an NCBA push ended, and settle it.
 *
 * NCBA Till STK Push has no callback, so where Daraja tells us, here we ask:
 * stk-push/query answers SUCCESS or FAILED. The answer is applied through
 * MpesaPaymentsController::applyStkResult -- the same settlement a Daraja
 * callback gets -- so an NCBA fare leaves exactly the trail a Safaricom one
 * does. Called by PollNcbaStkPush while the prompt is live, and by
 * payments:reconcile for anything still open after it.
 *
 * Idempotent: a settled push (processed_at) is never queried again, and the
 * settlement itself refuses to apply twice.
 */
class NcbaStkSettlement
{
    public const SETTLED = 'settled';

    public const FAILED = 'failed';

    /** NCBA has not decided yet; ask again. */
    public const PENDING = 'pending';

    /** No usable answer (NCBA unreachable, no token); ask again. */
    public const UNKNOWN = 'unknown';

    /** Already settled, or not an NCBA push: nothing to do. */
    public const DONE = 'done';

    public function __construct(private readonly NcbaStkClient $ncba) {}

    public function check(MpesaStkCallback $push): string
    {
        if ($push->isProcessed() || ! $push->isNcba() || $push->checkout_request_id === null) {
            return self::DONE;
        }

        $answer = $this->ncba->query($push->checkout_request_id);
        if ($answer === null) {
            return self::UNKNOWN;
        }

        $a = array_change_key_case($answer, CASE_LOWER);
        $status = strtoupper(trim((string) ($a['status'] ?? '')));
        $description = isset($a['description']) ? (string) $a['description'] : null;

        if ($status === 'SUCCESS') {
            [$vehicle, $amount] = $this->whatWasCharged($push);
            app(MpesaPaymentsController::class)->applyStkResult($push, $vehicle?->brand, 0, $description, [
                'amount' => $amount,
                'transid' => $this->receipt($push, $vehicle, $amount),
                // Nairobi wall-clock, as Daraja's TransactionDate is.
                'transdate' => Carbon::now('Africa/Nairobi')->format('Y-m-d H:i:s'),
                'phone' => (string) $push->phone,
            ], ['source' => 'ncba_stk_query', 'TransactionID' => $push->checkout_request_id, 'answer' => $answer]);

            return self::SETTLED;
        }

        if ($status === 'FAILED') {
            // result_code stays null: NCBA gives no code, and Daraja's numbers
            // mean specific things on the status screen (1 is "insufficient
            // funds"). NCBA's own description is what the passenger is shown.
            [$vehicle] = $this->whatWasCharged($push);
            app(MpesaPaymentsController::class)->applyStkResult($push, $vehicle?->brand, null, $description ?: 'Payment was not completed.', null, $answer);

            return self::FAILED;
        }

        return self::PENDING;
    }

    /**
     * The bus, and the amount the prompt asked for: the booking's server-set
     * fare, or the QR payment's amount -- the same numbers the push sent.
     *
     * @return array{0: Vehicle|null, 1: float}
     */
    private function whatWasCharged(MpesaStkCallback $push): array
    {
        if ($push->booking_id > 0) {
            $booking = Booking::withoutGlobalScopes()->with(['queue' => fn ($q) => $q->withoutGlobalScopes()])->find($push->booking_id);
            $vehicle = $booking?->queue?->vehicle_id ? Vehicle::withoutGlobalScopes()->find($booking->queue->vehicle_id) : null;

            return [$vehicle, (float) round((float) ($booking->amount ?? 0))];
        }

        $payment = QrcodePayment::withoutGlobalScopes()->find($push->qrcode_payment_id);

        return [$payment ? Vehicle::withoutGlobalScopes()->find($payment->vehicle_id) : null, (float) ($payment->amount ?? 0)];
    }

    /**
     * The M-Pesa receipt, when NCBA's confirmation of this payment is already in.
     *
     * The query answer carries no receipt. The money itself arrives on the
     * confirmation NCBA posts for every 880100 payment, filed against the bus's
     * till as the account number: the first such payment of this amount since
     * the prompt went out, not already claimed by another push, is this one.
     * If it has not landed yet, NCBA's TransactionID stands in -- unique per
     * push, which the receipt column needs, and enough to find it later.
     */
    private function receipt(MpesaStkCallback $push, ?Vehicle $vehicle, float $amount): string
    {
        $fallback = 'NCBA-'.$push->checkout_request_id;
        if ($vehicle === null || ! filled($vehicle->till_number)) {
            return $fallback;
        }

        $receipt = DB::table('mpesas')
            ->where('BusinessShortCode', $this->ncba->paybill())
            ->where('BillRefNumber', (string) $vehicle->till_number)
            ->whereRaw('CAST("TransAmount" AS numeric) = ?', [$amount])
            ->where('created_at', '>=', $push->created_at->copy()->subMinute())
            // Not a receipt another push already settled with.
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from((new MpesaBookingCallback)->getTable().' as b')->whereColumn('b.transid', 'mpesas.TransID'))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from((new MpesaQrcodePayment)->getTable().' as q')->whereColumn('q.transid', 'mpesas.TransID'))
            ->orderBy('created_at')
            ->value('TransID');

        return is_string($receipt) && $receipt !== '' ? $receipt : $fallback;
    }
}
