<?php

declare(strict_types=1);

namespace App\Services\Wallet\Payout;

use App\Models\MpesaPaymentSetting;
use App\Models\WalletSettlement;
use App\Services\Wallet\WalletFloat;
use App\Support\Shillings;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Safaricom Business-to-Business, from the Komiut float shortcode to a SACCO's
 * paybill.
 *
 * SHIPPED DISABLED, AND IT WILL STAY DISABLED UNTIL SOMEONE PROVISIONS IT.
 * enabled() needs four things that do not exist in any environment today:
 *
 *   the float shortcode          (wallet.float.short_code, also the collection gate)
 *   an initiator name            (WALLET_PAYOUT_INITIATOR)
 *   a SecurityCredential         (WALLET_PAYOUT_SECURITY_CREDENTIAL) -- the
 *                                initiator password RSA-encrypted with
 *                                Safaricom's PRODUCTION certificate. It is not
 *                                a password and cannot be computed here; the
 *                                cert is a file Safaricom issues.
 *   a result URL                 B2B is ASYNCHRONOUS. The POST only says
 *                                "accepted"; the outcome arrives later on
 *                                ResultURL.
 *
 * WHY IT IS WRITTEN AT ALL, UNTESTABLE AS IT IS. Having the shape in place
 * makes provisioning a config change rather than a design conversation, and it
 * documents precisely what to ask Safaricom for. But it has never moved a
 * shilling and nothing should assume it has: the working payout path today is a
 * human paying by transfer and recording the reference
 * (WalletSettlementService::markPaid with rail 'manual').
 *
 * EVERY ANSWER IS TREATED AS UNKNOWN RATHER THAN SUCCESS. Even a 200 from B2B
 * means only that Safaricom accepted the instruction -- the money has not
 * necessarily left. Reporting that as sent would let the settlement be marked
 * paid on an instruction that later failed, and a SACCO would be short with the
 * row saying otherwise.
 */
final class DarajaB2bPayoutRail implements WalletPayoutRail
{
    public function __construct(private WalletFloat $float) {}

    public function name(): string
    {
        return 'daraja_b2b';
    }

    public function enabled(): bool
    {
        return $this->float->shortCode() !== null
            && filled(config('wallet.payout.daraja_b2b.initiator'))
            && filled(config('wallet.payout.daraja_b2b.security_credential'))
            && filled(config('wallet.payout.daraja_b2b.result_url'));
    }

    public function send(WalletSettlement $settlement): PayoutResult
    {
        if (! $this->enabled()) {
            return PayoutResult::failed('The B2B payout rail is not configured.');
        }

        $receiver = $this->receivingShortCodeFor((int) $settlement->sacco_id);

        if ($receiver === null) {
            // Refused rather than guessed. Sending a SACCO's money to a
            // shortcode we inferred would be unrecoverable.
            return PayoutResult::failed('This SACCO has no M-Pesa shortcode on file to pay into.');
        }

        $token = $this->token();

        if ($token === null) {
            return PayoutResult::failed('Safaricom did not issue a token.');
        }

        try {
            $response = Http::withToken($token)->timeout(30)->acceptJson()->post(
                $this->base().'/mpesa/b2b/v1/paymentrequest',
                [
                    'Initiator' => (string) config('wallet.payout.daraja_b2b.initiator'),
                    'SecurityCredential' => (string) config('wallet.payout.daraja_b2b.security_credential'),
                    'CommandID' => 'BusinessPayBill',
                    'SenderIdentifierType' => '4',
                    'RecieverIdentifierType' => '4',
                    'Amount' => (string) Shillings::toShillings((int) $settlement->gross_cents),
                    'PartyA' => (string) $this->float->shortCode(),
                    'PartyB' => $receiver,
                    // THE IDEMPOTENCY KEY, carried as the account reference so
                    // the receiving end and any later reconciliation can both
                    // tie the money to this settlement and to no other.
                    'AccountReference' => $settlement->reference,
                    'Requester' => (string) $this->float->shortCode(),
                    'Remarks' => 'Komiut wallet settlement '.$settlement->reference,
                    'QueueTimeOutURL' => (string) (config('wallet.payout.daraja_b2b.timeout_url') ?: config('wallet.payout.daraja_b2b.result_url')),
                    'ResultURL' => (string) config('wallet.payout.daraja_b2b.result_url'),
                ],
            );
        } catch (Throwable $e) {
            // A TIMEOUT IS NOT A FAILURE. The instruction may have been
            // accepted and the money may be moving, so this must never come
            // back as "did not send" -- a blind retry on that would pay twice.
            Log::error('wallet payout: b2b unreachable', [
                'settlement' => $settlement->reference,
                'error' => $e->getMessage(),
            ]);

            return PayoutResult::pending(null, 'Safaricom did not answer. Confirm before retrying.');
        }

        $json = $response->json();

        if (! $response->successful() || ! is_array($json)) {
            Log::warning('wallet payout: b2b refused', [
                'settlement' => $settlement->reference,
                'status' => $response->status(),
                'body' => mb_substr((string) $response->body(), 0, 300),
            ]);

            $said = is_array($json) ? ($json['errorMessage'] ?? null) : null;

            return PayoutResult::failed(is_string($said) && $said !== '' ? mb_substr($said, 0, 200) : 'Safaricom refused the payout.');
        }

        // ACCEPTED, NOT PAID. See the class note: the outcome arrives on
        // ResultURL, so the settlement stays unpaid until something
        // authoritative confirms it.
        return PayoutResult::pending(
            isset($json['ConversationID']) ? (string) $json['ConversationID'] : null,
            'Accepted by Safaricom; awaiting the result callback.',
        );
    }

    /**
     * Where this SACCO's money should land.
     *
     * Read from the SACCO's own M-Pesa settings -- the shortcode it already
     * collects on -- because that is an account they have proven they control.
     * Taking a destination from anywhere a request could influence would make
     * this endpoint a way to redirect a SACCO's takings.
     */
    private function receivingShortCodeFor(int $saccoId): ?string
    {
        $code = MpesaPaymentSetting::withoutGlobalScopes()
            ->where('sacco_id', $saccoId)
            ->orderByDesc('is_default')
            ->value('business_short_code');

        $code = trim((string) $code);

        return $code === '' ? null : $code;
    }

    private function token(): ?string
    {
        try {
            $response = Http::withBasicAuth(
                (string) config('wallet.float.consumer_key'),
                (string) config('wallet.float.consumer_secret'),
            )->timeout(15)->acceptJson()->get($this->base().'/oauth/v1/generate', ['grant_type' => 'client_credentials']);
        } catch (Throwable $e) {
            Log::warning('wallet payout: token unreachable', ['error' => $e->getMessage()]);

            return null;
        }

        $token = $response->json('access_token');

        return is_string($token) && $token !== '' ? $token : null;
    }

    private function base(): string
    {
        return ((bool) config('services.mpesa.live', true) ? 'https://api' : 'https://sandbox').'.safaricom.co.ke';
    }
}
