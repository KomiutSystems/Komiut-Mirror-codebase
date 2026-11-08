<?php

declare(strict_types=1);

namespace App\Http\Controllers\APIs\Payments;

use App\Http\Controllers\Controller;
use App\Services\Mpesa\C2bPaymentRecorder;
use App\Services\Wallet\WalletFloat;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Safaricom's C2B confirmation for the KOMIUT FLOAT shortcode, and the only
 * place on earth a confirmation may credit a wallet.
 *
 * WHY THIS EXISTS AS ITS OWN ENDPOINT. The generic receiver,
 * `/api/confirmation/{id}`, is unauthenticated and has to be -- Safaricom
 * presents no credential, and 1.3M real confirmations have been delivered to
 * that URL shape. For a fare that is tolerable: a forged POST writes a visible,
 * fixable `transactions` row. For a wallet it is not, because the same forgery
 * mints withdrawable balance out of nothing, and the thing that was deciding
 * "this money landed in Komiut's account" was `BusinessShortCode` -- a field in
 * the caller's own request body.
 *
 * So the proof moved from the body to the URL. We register THIS path with
 * Safaricom for the float shortcode, the secret in it is not guessable, and
 * C2bPaymentRecorder refuses to credit a wallet for any caller that cannot pass
 * `trustedForWallet: true` -- which only this controller does.
 *
 * THREE LOCKS, in order of how much they are relied on:
 *
 *   the secret     authenticates the caller. Unset => nothing credits, ever.
 *   the IP list    optional defence in depth (Safaricom's egress addresses). An
 *                  empty list passes, because a wrong address here would refuse
 *                  real money and the secret is already authenticating.
 *   the shortcode  WalletTopUpService still checks the body's BusinessShortCode
 *                  against the float. Necessary, not sufficient -- it is the
 *                  check this controller exists to stand in front of.
 *
 * IT ALWAYS ANSWERS 200 WITH SAFARICOM'S EXPECTED BODY, even on a rejected
 * secret. A non-2xx makes Safaricom retry, and a 500 on a payment webhook is
 * how the legacy backend lost 52 payments. A probe learns nothing from the
 * response either way; the rejection goes to the log, not to the caller.
 */
class WalletC2bConfirmationController extends Controller
{
    public function __construct(
        private C2bPaymentRecorder $recorder,
        private WalletFloat $float,
    ) {}

    public function __invoke(Request $request, string $secret): Response
    {
        if (! $this->float->confirmationSecretMatches($secret)) {
            // At WARNING with no echo of what was sent: either somebody is
            // probing, or the URL registered with Safaricom has drifted from
            // the configured secret -- and the second one is an outage worth
            // finding in the log.
            Log::warning('wallet c2b: confirmation secret rejected', [
                'ip' => $request->ip(),
                'short_code' => $request->input('BusinessShortCode'),
            ]);

            return $this->ack();
        }

        if (! $this->float->sourceIpAllowed($request->ip())) {
            Log::warning('wallet c2b: confirmation from an address outside the allowlist', [
                'ip' => $request->ip(),
            ]);

            return $this->ack();
        }

        $fields = (array) $request->all();

        // THE ONLY CALL IN THE CODEBASE THAT PASSES trustedForWallet: true.
        //
        // Vehicle resolution deliberately returns null: money on the FLOAT
        // shortcode belongs to a passenger's wallet, matched by account number,
        // and attributing it to a bus as well would count one payment as both
        // float and fare. The payment is still recorded, so nothing is lost if
        // the account number turns out to match no wallet -- the admin console's
        // unmatched screen exists for exactly that.
        $result = $this->recorder->record($fields, fn () => null, trustedForWallet: true);

        if (! $result->ok) {
            Log::error('wallet c2b: recording failed', [
                'trans_id' => $fields['TransID'] ?? null,
                'error' => $result->error,
            ]);
        }

        return $this->ack();
    }

    /**
     * What Safaricom expects. Matches the generic receiver's shape exactly --
     * text/xml carrying JSON -- because that is what Safaricom has accepted
     * 1.3M times and matching it removes one variable from the registration.
     */
    private function ack(): Response
    {
        $response = new Response(json_encode(['ResultCode' => 0, 'ResultDesc' => 'Accepted']));
        $response->headers->set('Content-Type', 'text/xml; charset=utf-8');

        return $response;
    }
}
