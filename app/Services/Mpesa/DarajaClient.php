<?php

declare(strict_types=1);

namespace App\Services\Mpesa;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Thin Daraja (Safaricom M-Pesa) client for the POLLING side of payments —
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
