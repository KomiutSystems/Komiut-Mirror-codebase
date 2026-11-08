<?php

declare(strict_types=1);

namespace App\Http\Controllers\APIs\Payments;

use App\Http\Controllers\Controller;
use App\Services\Wallet\WalletTopUpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Safaricom's verdict on a wallet top-up prompt.
 *
 * THIS IS WHERE AN STK TOP-UP IS CREDITED, because a Lipa-na-M-Pesa-Online
 * payment is delivered here and NOT to the C2B confirmation URL -- the same
 * reason MpesaPaymentsController::stkResponse settles bookings from
 * CallbackMetadata. A manual paybill top-up is the other path and arrives as an
 * ordinary C2B confirmation.
 *
 * IT IS UNAUTHENTICATED, which it has to be -- Safaricom presents no
 * credential -- so NOTHING HERE IS TRUSTED. Two defences, both in
 * WalletTopUpService:
 *
 *   - a success is re-checked with Daraja's stkQuery before a shilling moves,
 *     over an authenticated channel a forged POST cannot reach, and fails
 *     CLOSED when Daraja gives no answer;
 *   - the amount credited is the one WE sent at initiation, never the one in
 *     this body, so a forged callback cannot inflate it. An STK prompt charges
 *     exactly what was requested, so there is nothing to lose by ignoring it.
 *
 * The URL carries a 32-byte nonce rather than the row id, matching the existing
 * `stk/push/response/{nonce}`: a sequential path would let anyone walk other
 * passengers' top-ups.
 *
 * ALWAYS ANSWERS 200 WITH DARAJA'S EXPECTED BODY. A non-2xx makes Safaricom
 * retry, and a 500 here is how the legacy backend lost 52 payments: it got a
 * 500 instead of the ResultCode JSON and the payment vanished from our side
 * while the money had already left the customer. Nothing in this handler is
 * worth a retry storm.
 */
class WalletTopUpCallbackController extends Controller
{
    public function __construct(private WalletTopUpService $topUps) {}

    public function __invoke(Request $request, string $nonce): JsonResponse
    {
        $callback = (array) $request->input('Body.stkCallback', []);

        try {
            $attempt = $this->topUps->applyRailVerdict($nonce, $callback);

            if ($attempt === null) {
                // A nonce we never minted. Logged at warning rather than
                // answered with an error: it is either a stale callback for a
                // row since removed, or somebody probing. Neither deserves a
                // retry.
                Log::warning('wallet top-up callback: unknown nonce', [
                    'result_code' => $callback['ResultCode'] ?? null,
                ]);
            }
        } catch (\Throwable $e) {
            // Swallowed for the reason in the class docblock. The attempt stays
            // pending and the timeout sweep will resolve it.
            Log::error('wallet top-up callback failed', [
                'exception' => $e::class,
                'error' => $e->getMessage(),
            ]);
        }

        return response()->json(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);
    }
}
