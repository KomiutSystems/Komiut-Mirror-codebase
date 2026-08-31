<?php

declare(strict_types=1);

namespace App\Support\Http;

use App\Http\Controllers\APIs\BillingMpesaController;
use App\Http\Controllers\APIs\C2bConfirmationController;
use App\Http\Controllers\APIs\CoopRestPaymentsController;
use App\Http\Controllers\APIs\MpesaPaymentsController;
use App\Http\Controllers\APIs\NCBARestPaymentsController;
use App\Http\Controllers\APIs\NCBASoapPaymentsController;
use Illuminate\Http\Request;

/**
 * The handlers that banks and Safaricom post MONEY to.
 *
 * WHY THIS LIST EXISTS. The `api` middleware group carries `throttle:api`, which
 * is 60 requests per minute keyed on the client IP. That is the right shape for
 * a mobile app and exactly the wrong shape here, for one reason: every C2B
 * confirmation in the fleet arrives from a SINGLE forwarding host. The limiter
 * cannot see 800 different matatus, it sees one IP — so at morning peak the
 * fleet's own payments push each other over the cap.
 *
 * WHAT THAT COST. On 2026-08-31 the confirmation URLs answered 879 requests with
 * HTTP 429. ThrottleRequests runs BEFORE the controller, so C2bConfirmationController
 * never ran — which means not even its first statement, the raw-body MpesaLog
 * write that exists precisely so a dropped payment can be reconstructed, ever
 * happened. 1,379 payments that legacy holds are absent here with NO trace of any
 * kind. KDX 439C alone lost 16 fares between 06:38 and 07:44, and the SACCO's
 * finance officer found it by reading a till balance off the M-Pesa portal.
 *
 * WHY UNLIMITED IS THE RIGHT ANSWER, not merely a higher number. A cap on this
 * path fails by destroying money silently and irreversibly; there is no retry
 * from Safaricom we control and no local record to repair from. Any finite number
 * is a bet that the fleet never grows into it, and losing that bet looks exactly
 * like this incident. The endpoints are safe to leave open because recording is
 * IDEMPOTENT — C2bPaymentRecorder dedupes on TransID and only rolls a summary
 * forward for a transaction it actually created — so a flood costs rows, not
 * corrupted takings. Flood protection for these paths belongs at the edge (ALB /
 * WAF), where refusing a request cannot swallow a fare.
 *
 * NOT IN THIS LIST, deliberately: MpesaPaymentsController's STK PUSH triggers
 * (customerMpesaSTKPush, customerQRCodeSTKPush). Those are outbound — they raise
 * a real PIN prompt on somebody's handset — so they keep their own throttles.
 * Only the inbound callbacks are exempt. That is why this matches on
 * Controller@method rather than on a controller class.
 */
final class MoneyIngestion
{
    /**
     * Exact `Controller@method` actions that RECEIVE money notifications.
     *
     * @var list<string>
     */
    private const HANDLERS = [
        // Safaricom C2B, per-till. ~98.6% of revenue arrives here.
        C2bConfirmationController::class.'@confirmation',
        C2bConfirmationController::class.'@validation',

        // NCBA push notifications (SOAP and REST, branded and brand-less).
        NCBASoapPaymentsController::class.'@mpesaPayments',
        NCBARestPaymentsController::class.'@restMpesaPayments',
        NCBARestPaymentsController::class.'@restMpesaNewPayments',
        NCBARestPaymentsController::class.'@mpesaNewPayments',

        // Co-operative Bank.
        CoopRestPaymentsController::class.'@coopMpesaPayments',
        CoopRestPaymentsController::class.'@coopMpesaStkCallback',

        // SACCO subscription billing C2B.
        BillingMpesaController::class.'@confirmation',
        BillingMpesaController::class.'@validation',

        // Daraja STK callbacks (inbound). The legacy path only logs forgeries,
        // but it must not be able to 429 either, or the log loses the evidence.
        MpesaPaymentsController::class.'@stkResponse',
        MpesaPaymentsController::class.'@stkResponseLegacy',
    ];

    /**
     * Is this request a bank or Safaricom delivering a payment notification?
     */
    public static function matches(Request $request): bool
    {
        $action = $request->route()?->getActionName();

        return $action !== null && in_array($action, self::HANDLERS, true);
    }
}
