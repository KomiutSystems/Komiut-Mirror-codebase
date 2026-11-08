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
 * IT CANNOT MOVE MONEY, and that is deliberate rather than incidental. Money
 * enters a wallet in exactly one place -- WalletTopUpService::settleFromConfirmation,
 * from a C2B confirmation that landed on the Komiut float shortcode. This
 * endpoint only moves a pending attempt to a terminal state sooner than the
 * timeout would, so the passenger's spinner stops.
 *
 * That is what makes it safe to leave unauthenticated, which it has to be:
 * Safaricom posts here and presents no credential. The URL carries a 32-byte
 * nonce rather than the row id, matching the existing
 * `stk/push/response/{nonce}` -- a sequential path would let anyone mark
 * another passenger's top-up cancelled. The worst a forged callback can do is
 * misreport a status that the next confirmation corrects.
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

        $resultCode = $callback['ResultCode'] ?? null;
        $resultDesc = $callback['ResultDesc'] ?? null;

        try {
            $attempt = $this->topUps->applyRailVerdict(
                $nonce,
                $resultCode === null ? null : (string) $resultCode,
                $resultDesc === null ? null : (string) $resultDesc,
            );

            if ($attempt === null) {
                // A nonce we never minted. Logged at warning rather than
                // answered with an error: it is either a stale callback for a
                // row since removed, or somebody probing. Neither deserves a
                // retry.
                Log::warning('wallet top-up callback: unknown nonce', [
                    'result_code' => $resultCode,
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
