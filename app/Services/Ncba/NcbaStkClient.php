<?php

declare(strict_types=1);

namespace App\Services\Ncba;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * NCBA Till STK Push, as specified in NCBA's "NCBA TILL STK PUSH & DYNAMIC QR
 * CODE API" (2024).
 *
 * WHY IT EXISTS. A bus on NCBA's aggregator collects on paybill 880100 with its
 * own NCBA till short code as the account number. Safaricom's STK push needs a
 * Daraja app for the paybill, and 880100 is NCBA's, not ours, so those buses
 * could take no in-app payment at all: the push answered "This vehicle is not
 * set up for M-Pesa payments yet." NCBA pushes the prompt on its own paybill
 * instead, and the money arrives the way every 880100 payment does, on the
 * confirmation NCBA already posts to us.
 *
 * THE SPEC, IN THREE CALLS (BASE = https://c2bapis.ncbagroup.com):
 *
 *   GET  /payments/api/v1/auth/token        Basic auth with the username and
 *        secret key the business gave the bank in its signed letter. Returns
 *        a bearer token valid `expires_in` seconds (18000, five hours).
 *   POST /payments/api/v1/stk-push/initiate TelephoneNo, Amount, PayBillNo,
 *        AccountNo, Network, TransactionType, all strings. Returns
 *        TransactionID / StatusCode / StatusDescription / ReferenceID;
 *        StatusCode "1" with a null TransactionID is a refusal.
 *   POST /payments/api/v1/stk-push/query    TransactionID. Returns status
 *        SUCCESS or FAILED with a description.
 *
 * There is NO callback in the spec: the result is learned by querying, which
 * PollNcbaStkPush does while the prompt is live and payments:reconcile does
 * after.
 *
 * Every method returns null for "no usable answer" (unreachable, unreadable,
 * no token), never a guess. The token and the credentials are never logged.
 */
class NcbaStkClient
{
    private const TOKEN_CACHE_KEY = 'ncba_stk:token';

    public function enabled(): bool
    {
        return filled(config('services.ncba_stk.username')) && filled(config('services.ncba_stk.password'));
    }

    /** NCBA's aggregator paybill: the business number every 880100 till collects on. */
    public function paybill(): string
    {
        return (string) config('services.ncba_stk.paybill', '880100');
    }

    /**
     * Ask NCBA to put a payment prompt on the payer's phone.
     *
     * @param  string  $msisdn  2547XXXXXXXX
     * @param  string  $accountNo  the bus's NCBA till short code (vehicles.till_number)
     * @return array<string, mixed>|null
     */
    public function initiate(string $msisdn, int $amount, string $accountNo): ?array
    {
        return $this->post('/payments/api/v1/stk-push/initiate', [
            'TelephoneNo' => $msisdn,
            'Amount' => (string) $amount,
            'PayBillNo' => $this->paybill(),
            'AccountNo' => $accountNo,
            'Network' => 'Safaricom',
            'TransactionType' => 'CustomerPayBillOnline',
        ], 'initiate');
    }

    /**
     * The authoritative outcome of an earlier push.
     *
     * @return array<string, mixed>|null
     */
    public function query(string $transactionId): ?array
    {
        return $this->post('/payments/api/v1/stk-push/query', ['TransactionID' => $transactionId], 'query');
    }

    /**
     * Bearer token, reused until shortly before NCBA says it expires.
     *
     * Not Cache::remember: a failed request must not be remembered, and the
     * lifetime comes from NCBA's own `expires_in`, not a constant.
     */
    public function token(): ?string
    {
        $cached = Cache::get(self::TOKEN_CACHE_KEY);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        try {
            $res = Http::withBasicAuth((string) config('services.ncba_stk.username'), (string) config('services.ncba_stk.password'))
                ->timeout(15)
                ->acceptJson()
                ->get($this->base().'/payments/api/v1/auth/token');
        } catch (Throwable $e) {
            Log::warning('ncba stk: token request unreachable', ['error' => $e->getMessage()]);

            return null;
        }

        $token = $res->json('access_token');
        if (! $res->successful() || ! is_string($token) || $token === '') {
            // 401 is "Invalid API credentials": the username/secret on the
            // bank's letter and in SSM disagree. No body logged: it echoes nothing
            // useful and the request carried the credentials.
            Log::warning('ncba stk: token refused', ['status' => $res->status()]);

            return null;
        }

        $ttl = max(60, (int) $res->json('expires_in', 18000) - 300);
        Cache::put(self::TOKEN_CACHE_KEY, $token, $ttl);

        return $token;
    }

    private function base(): string
    {
        return rtrim((string) config('services.ncba_stk.base_url', 'https://c2bapis.ncbagroup.com'), '/');
    }

    /**
     * @param  array<string, string>  $body
     * @return array<string, mixed>|null
     */
    private function post(string $path, array $body, string $what): ?array
    {
        $token = $this->token();
        if ($token === null) {
            return null;
        }

        try {
            $res = Http::withToken($token)->timeout(20)->acceptJson()->post($this->base().$path, $body);
        } catch (Throwable $e) {
            Log::warning("ncba stk: {$what} unreachable", ['error' => $e->getMessage()]);

            return null;
        }

        if ($res->status() === 401) {
            // A token NCBA no longer honours: drop it so the next call fetches a fresh one.
            Cache::forget(self::TOKEN_CACHE_KEY);
        }

        $json = $res->json();
        if (! $res->successful() || ! is_array($json)) {
            Log::warning("ncba stk: {$what} refused", [
                'status' => $res->status(),
                'body' => mb_substr((string) $res->body(), 0, 300),
            ]);

            return is_array($json) && $res->clientError() ? $json : null;
        }

        return $json;
    }
}
