<?php

declare(strict_types=1);

namespace App\Services\Mpesa;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Thin Daraja (Safaricom M-Pesa) client for the parts of the API this platform
 * drives itself: verifying a push after the fact, and registering where C2B
 * payments should be delivered.
 *
 * The polling side —
 * querying the authoritative status of an STK push so a lost or delayed callback
 * doesn't strand a booking the customer actually paid for. Constructed with one
 * merchant's credentials (per-SACCO or per-vehicle), resolved by
 * MpesaCredentialResolver.
 *
 * Webhook = speed; this = verification. A spoofed callback can't fake a query
 * result, because the answer comes from Safaricom over an authenticated channel.
 */
class DarajaClient
{
    public function __construct(
        private readonly string $consumerKey,
        private readonly string $consumerSecret,
        private readonly string $shortCode,
        private readonly string $passKey,
        private readonly bool $live,
    ) {}

    private function base(): string
    {
        return ($this->live ? 'https://api' : 'https://sandbox') . '.safaricom.co.ke';
    }

    /** OAuth bearer token, cached ~55 min (Daraja tokens live 1 hour). */
    public function token(): ?string
    {
        $cacheKey = 'daraja_token_' . md5($this->consumerKey . '|' . $this->base());

        return Cache::remember($cacheKey, now()->addMinutes(55), function () {
            $res = Http::withBasicAuth($this->consumerKey, $this->consumerSecret)
                ->timeout(15)
                ->get($this->base() . '/oauth/v1/generate', ['grant_type' => 'client_credentials']);

            if (! $res->ok()) {
                Log::warning('daraja token request failed', ['status' => $res->status()]);

                return null;
            }

            return $res->json('access_token');
        });
    }

    /**
     * Register this merchant's C2B callback URLs with Safaricom.
     *
     * THE MOST CONSEQUENTIAL CALL IN THE PLATFORM. It tells Safaricom where to
     * deliver every future payment on a shortcode, so getting it wrong sends
     * real money somewhere nobody is listening. There is no dry run: the only
     * way back is to register the previous URL again.
     *
     * $shortCode is a PARAMETER, not $this->shortCode. The credential's own
     * shortcode and the till being registered are routinely different — one
     * Daraja app covers a whole fleet, and each bus has its own
     * merchant_short_code. Registering the credential's shortcode instead of the
     * bus's is the mistake this signature exists to prevent, and it would move
     * one bus's money onto another's till.
     *
     * Returns Safaricom's decoded response, or null when the request could not
     * be made at all. A null is "we do not know", never "it worked" — the caller
     * must not record a registration it cannot prove.
     *
     * @return array<string, mixed>|null
     */
    public function registerC2bUrls(string $shortCode, string $confirmationUrl, string $validationUrl): ?array
    {
        $token = $this->token();
        if (! $token) {
            return null;
        }

        $res = Http::withToken($token)->timeout(30)
            ->post($this->base() . '/mpesa/c2b/v2/registerurl', [
                // intval, matching the legacy tier: Safaricom rejects a shortcode
                // carrying spaces or a leading zero, and these are hand-entered.
                'ShortCode' => (string) intval($shortCode),
                'ResponseType' => 'Completed',
                'ConfirmationURL' => $confirmationUrl,
                'ValidationURL' => $validationUrl,
            ]);

        if ($res->serverError()) {
            Log::warning('daraja registerurl failed', [
                'short_code' => $shortCode,
                'status' => $res->status(),
            ]);

            return null;
        }

        return $res->json();
    }

    /**
     * Register a shortcode for Daraja's Pull Transactions API. Once per
     * shortcode; Safaricom answers a repeat with an "already registered"
     * description, which is fine. The callback is required by the API but the
     * data comes back synchronously from pullQuery(), so the URL only logs.
     *
     * @return array<string, mixed>|null  null on a transient/network error
     */
    public function registerPull(string $shortCode, string $nominatedNumber, string $callbackUrl): ?array
    {
        $token = $this->token();
        if (! $token) {
            return null;
        }

        // A register call Safaricom leaves hanging is "no answer" for this
        // shortcode, not the end of the run: on 2026-09-25 one 30s timeout
        // stopped a registration of 170 tills a third of the way through.
        try {
            $res = Http::withToken($token)->timeout(30)
                ->post($this->base() . '/pulltransactions/v1/register', [
                    'ShortCode' => (string) intval($shortCode),
                    'RequestType' => 'Pull',
                    'NominatedNumber' => $nominatedNumber,
                    'CallBackURL' => $callbackUrl,
                ]);
        } catch (\Throwable $e) {
            Log::warning('daraja pull register: no answer', ['short_code' => $shortCode, 'error' => $e->getMessage()]);

            return null;
        }

        if ($res->serverError()) {
            Log::warning('daraja pull register failed', ['short_code' => $shortCode, 'status' => $res->status()]);

            return null;
        }

        return $res->json() ?? ['http' => $res->status(), 'body' => substr($res->body(), 0, 300)];
    }

    /**
     * One page of the Pull Transactions API: every C2B transaction Safaricom
     * holds for the shortcode in the window (it keeps about 48 hours), at most
     * 1,000 per call, continued with $offset. This is the only Daraja call that
     * can show us a payment whose confirmation was never delivered.
     *
     * @return array<string, mixed>|null  null on a transient/network error
     */
    public function pullQuery(string $shortCode, string $startDate, string $endDate, int $offset = 0): ?array
    {
        $token = $this->token();
        if (! $token) {
            return null;
        }

        try {
            $res = Http::withToken($token)->timeout(60)
                ->post($this->base() . '/pulltransactions/v1/query', [
                    'ShortCode' => (string) intval($shortCode),
                    'StartDate' => $startDate,
                    'EndDate' => $endDate,
                    'OffSetValue' => (string) $offset,
                ]);
        } catch (\Throwable) {
            return null;
        }

        // Safaricom documents "no transactions for this shortcode" as
        // ResponseCode 500 in the body, so a server-error status with a
        // readable body is an answer, not an outage. Only an unreadable
        // response is "no answer".
        $body = $res->json();
        if (! is_array($body)) {
            return $res->serverError() ? null : ['_http' => $res->status()];
        }

        return $body + ['_http' => $res->status()];
    }

    private function password(string $timestamp): string
    {
        return base64_encode($this->shortCode . $this->passKey . $timestamp);
    }

    /**
     * STK Push Query — authoritative status of a push by CheckoutRequestID.
     * Returns the decoded response, or null on a transient/network error (retry
     * next run). Key field: ResultCode — "0" the customer paid; "1032" cancelled;
     * "1037" timeout/unreachable; "1"/"2001" failed.
     *
     * @return array<string, mixed>|null
     */
    public function stkQuery(string $checkoutRequestId): ?array
    {
        $token = $this->token();
        if (! $token) {
            return null;
        }

        $ts = Carbon::now()->format('YmdHis');
        $res = Http::withToken($token)->timeout(20)
            ->post($this->base() . '/mpesa/stkpushquery/v1/query', [
                'BusinessShortCode' => $this->shortCode,
                'Password' => $this->password($ts),
                'Timestamp' => $ts,
                'CheckoutRequestID' => $checkoutRequestId,
            ]);

        // 500s are transient (Safaricom often 500s a still-processing query);
        // treat as "unknown, retry". 4xx with a ResultCode is a real answer.
        if ($res->serverError()) {
            return null;
        }

        return $res->json();
    }
}
