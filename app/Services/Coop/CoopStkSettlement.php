<?php

declare(strict_types=1);

namespace App\Services\Coop;

use App\Brands\BrandContext;
use App\Brands\BrandRegistry;
use App\Http\Controllers\APIs\MpesaPaymentsController;
use App\Models\MpesaBookingCallback;
use App\Models\MpesaQrcodePayment;
use App\Models\MpesaStkCallback;
use App\Models\Vehicle;
use App\Services\Mpesa\StkPushSubject;
use App\Services\Super\Money\PaymentReconciliationAlerter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Find out how a Co-op push ended, and settle it.
 *
 * The outcome is always ASKED of the bank (Enquiry/STK), over our own
 * authenticated call, by the reference we gave the push. The bank's callback
 * only brings that question forward: nothing in the bank's material says how a
 * callback proves where it came from, so its contents are never what marks a
 * ride paid. The answer is applied through
 * MpesaPaymentsController::applyStkResult -- the same settlement a Daraja
 * callback gets. Called by PollCoopStkPush while the prompt is live (and when
 * a callback arrives), and by payments:reconcile for anything still open.
 *
 * Idempotent: a settled push (processed_at) is never asked about again, and
 * one lock per push means overlapping checks cannot both apply an answer.
 */
class CoopStkSettlement
{
    public const SETTLED = 'settled';

    public const FAILED = 'failed';

    /** The bank has not decided yet; ask again. */
    public const PENDING = 'pending';

    /** No usable answer (bank unreachable, no token, an answer that does not add up); ask again. */
    public const UNKNOWN = 'unknown';

    /** Already settled, refused outright, or not a Co-op push: nothing to do. */
    public const DONE = 'done';

    public function __construct(private readonly CoopStkClient $coop) {}

    public function check(MpesaStkCallback $push): string
    {
        if ($push->isProcessed() || ! $push->isCoop() || ! filled($push->callback_nonce) || self::wasRefused($push)) {
            return self::DONE;
        }

        $reference = (string) $push->callback_nonce;
        $answer = $this->coop->query($reference);
        if ($answer === null || ! $answer->ok()) {
            return self::UNKNOWN;
        }

        $verdict = $answer->verdict();
        if ($verdict === CoopStkAnswer::PENDING) {
            return self::PENDING;
        }

        // The callback's check, the poll chain and payments:reconcile can all
        // be holding the same push, each with a copy read before its own call
        // to the bank. One of them settles; the others find it done.
        $lock = Cache::lock('coop_stk:settle:'.$push->id, 30);
        if (! $lock->get()) {
            return self::PENDING;
        }

        try {
            $push = MpesaStkCallback::find($push->id);
            if ($push === null || $push->isProcessed()) {
                return self::DONE;
            }

            return $this->apply($push, $reference, $answer, $verdict);
        } finally {
            $lock->release();
        }
    }

    private function apply(MpesaStkCallback $push, string $reference, CoopStkAnswer $answer, string $verdict): string
    {
        [$vehicle, $amount] = StkPushSubject::of($push);
        $raw = ['source' => 'coop_stk_enquiry', 'MessageReference' => $reference, 'answer' => $answer->body];

        // Settlement belongs to the BUS's brand. A check queued by the bank's
        // callback inherits the brand of the host the bank posted to, under
        // which another brand's booking cannot even be seen.
        $brand = filled($vehicle?->brand) ? app(BrandRegistry::class)->get((string) $vehicle->brand) : null;
        $brand !== null ? app(BrandContext::class)->apply($brand) : Context::forget('brand');

        if ($verdict === CoopStkAnswer::FAILED) {
            // result_code stays null: Daraja's numbers mean specific things on
            // the status screen (1 is "insufficient funds"). The bank's own
            // description is what the passenger is shown.
            app(MpesaPaymentsController::class)->applyStkResult($push, $vehicle?->brand, null, $answer->description() ?: 'Payment was not completed.', null, $raw);

            return $push->isProcessed() ? self::FAILED : self::UNKNOWN;
        }

        // Paid needs a receipt: the bank's own, or -- when the answer only
        // SAYS paid -- the one on the payment the bank's feed has delivered.
        $receipt = $answer->receipt() ?? $this->receiptFromFeed($push, $vehicle, $amount);
        if ($receipt === null) {
            return self::PENDING;
        }

        // The bank says paid -- but a different sum than the prompt asked for
        // is not this fare. Left open and loud, for a person to look at.
        $paid = $answer->amount();
        if ($paid !== null && abs($paid - $amount) > 0.009) {
            Log::error('coop stk: paid amount differs from the fare pushed - not settled', [
                'stk_id' => $push->id, 'reference' => $reference, 'pushed' => $amount, 'paid' => $paid,
            ]);
            $this->needsAPerson($push, $vehicle, $receipt, $paid);

            return self::UNKNOWN;
        }

        // Nor is a payment from another number this passenger's.
        $payer = $answer->phone();
        if ($payer !== null && ltrim($payer, '+') !== ltrim((string) $push->phone, '+')) {
            Log::error('coop stk: paid from a different number than the one prompted - not settled', [
                'stk_id' => $push->id, 'reference' => $reference,
            ]);
            $this->needsAPerson($push, $vehicle, $receipt, $paid ?? $amount);

            return self::UNKNOWN;
        }

        // A push whose own request got no answer has no id yet; the reference
        // is the id, and the passenger's status poll finds it by that.
        if ($push->checkout_request_id === null) {
            $push->checkout_request_id = $reference;
        }

        app(MpesaPaymentsController::class)->applyStkResult($push, $vehicle?->brand, 0, $answer->description(), [
            'amount' => $amount,
            'transid' => $receipt,
            // Nairobi wall-clock, as Daraja's TransactionDate is.
            'transdate' => Carbon::now('Africa/Nairobi')->format('Y-m-d H:i:s'),
            'phone' => (string) $push->phone,
        ], $raw);

        // applyStkResult answers an error (booking gone) without closing the push.
        return $push->isProcessed() ? self::SETTLED : self::UNKNOWN;
    }

    /**
     * Money the bank says arrived, with a receipt, that we will not apply on
     * our own: onto the super console's payment alerts, where unmatched money
     * already goes, rather than only a log line nobody reads. Once per push --
     * every later check of it would otherwise count it again.
     */
    private function needsAPerson(MpesaStkCallback $push, ?Vehicle $vehicle, string $receipt, float $paid): void
    {
        if (Cache::add('coop_stk:alerted:'.$push->id, 1, now()->addDay())) {
            app(PaymentReconciliationAlerter::class)->record($vehicle?->brand, 'coop stk '.$push->id.' '.$receipt, $paid);
        }
    }

    /**
     * The bank answered the push request with a refusal: no prompt went out,
     * so there is nothing to ask about. Only a refusal the push RECORDED as one
     * counts (see pushThroughCoop). No answer, or an answer we could not read
     * as either, is different -- the bank may have taken the request -- and is
     * asked about like any other.
     */
    public static function wasRefused(MpesaStkCallback $push): bool
    {
        $said = json_decode((string) $push->callback, true);

        return $push->checkout_request_id === null && is_array($said) && ($said['CoopRefused'] ?? false) === true;
    }

    /**
     * The M-Pesa receipt, when the enquiry gave none but the bank's payment
     * feed already has this payment: same payer, same amount, on this bus's
     * till or account, AFTER the prompt went out, not claimed by another push.
     * This is the proof a receipt-less "paid" is held to, so it is strict.
     */
    private function receiptFromFeed(MpesaStkCallback $push, ?Vehicle $vehicle, float $amount): ?string
    {
        $codes = array_values(array_filter([(string) $vehicle?->merchant_short_code, (string) $vehicle?->coop_till]));
        if ($codes === [] || ! filled($push->phone)) {
            return null;
        }

        $receipt = DB::table('mpesas')
            ->whereIn('BusinessShortCode', $codes)
            ->where('MSISDN', (string) $push->phone)
            ->whereRaw('CAST("TransAmount" AS numeric) = ?', [$amount])
            ->where('created_at', '>=', $push->created_at)
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from((new MpesaBookingCallback)->getTable().' as b')->whereColumn('b.transid', 'mpesas.TransID'))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from((new MpesaQrcodePayment)->getTable().' as q')->whereColumn('q.transid', 'mpesas.TransID'))
            ->orderBy('created_at')
            ->value('TransID');

        return is_string($receipt) && $receipt !== '' ? $receipt : null;
    }
}
