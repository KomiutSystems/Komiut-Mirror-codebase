<?php

declare(strict_types=1);

namespace App\Services\Coop;

use App\Models\Vehicle;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Co-operative Bank STK Push, as the bank's production collection for
 * MetroTrans SACCO describes it (Digital Integrations & Ecommerce, 2026-11-25),
 * and as it answered live on 2026-11-26.
 *
 * WHY IT EXISTS. A bus banked with Co-op collects on a Co-op till: a Safaricom
 * till number that belongs to the BANK, settling to the bus's escrow account.
 * Safaricom's STK push needs the till owner's Daraja keys, and the bank does not
 * hand those out, so these buses could take no in-app payment at all: the push
 * answered "This vehicle is not set up for M-Pesa payments yet." The bank raises
 * the prompt through its own API instead.
 *
 * THE THREE CALLS (BASE = https://openapi.co-opbank.co.ke):
 *
 *   POST /token              Basic auth with the consumer key and secret, form
 *        body grant_type=client_credentials. A bearer token for an hour.
 *   POST /FT/stk/1.0.0       MessageReference (ours, unique), CallBackUrl,
 *        OperatorCode, TransactionCurrency, MobileNumber, Narration, Amount,
 *        MessageDateTime, OtherDetails [{Name, Value}]. No trailing slash.
 *        Accepted: {"MessageCode": "0", "MessageDescription": "REQUEST
 *        ACCEPTED FOR PROCESSING"}. An operator code the bank has not set up:
 *        {"PayResponse": null}, and no prompt.
 *   POST /Enquiry/STK/1.0.0/ {MessageReference}. "-13 Message Reference does
 *        not exist" until the bank has filed it, "S_001 PROCESSING", then
 *        "0 FULL SUCCESS" with the M-Pesa receipt inside the narration
 *        ("<our narration>~UKQ4340ZXN~2026-11-26"). See CoopStkAnswer.
 *
 * WHICH ACCOUNT. The operator code alone decides it: the bank files each
 * escrow account under one (vehicles.coop_operator_code). Sent exactly as the
 * bank set it up -- "KDW 319J" is not "KDW319J".
 *
 * NO CALLBACK. The bank said so ("utilize the status fetch"): the outcome is
 * always asked for (CoopStkSettlement, PollCoopStkPush).
 *
 * The body is sent the way the bank's collection sends it, slashes unescaped:
 * the gateway behind it is a SOAP service, and the collection is the only
 * format known to work.
 *
 * Both hosts sit behind an address allowlist: an unlisted caller gets an
 * "Access Denied" page (403) before any credential is read.
 *
 * Every call returns null for "no usable answer" (unreachable, unreadable, no
 * token), never a guess. The token and the credentials are never logged.
 */
class CoopStkClient
{
    private const TOKEN_CACHE_KEY = 'coop_stk:token';

    /**
     * An explicit switch as well as the credentials. NCBA's push went live the
     * moment its credentials reached the environment, before the bank had
     * activated them; this one waits to be turned on.
     */
    public function enabled(): bool
    {
        return (bool) config('services.coop_stk.enabled')
            && filled(config('services.coop_stk.consumer_key'))
            && filled(config('services.coop_stk.consumer_secret'));
    }

    /**
     * Is this a bus the bank raises prompts for? Only one the bank has filed
     * under an operator code: without it the bank answers with no prompt.
     */
    public function collectsFor(Vehicle $vehicle): bool
    {
        return filled($vehicle->coop_operator_code);
    }

    /** A reference of ours for one push: 19 letters and digits, unique. */
    public static function newReference(): string
    {
        return 'K'.strtoupper(bin2hex(random_bytes(9)));
    }

    /**
     * Ask the bank to put a payment prompt on the payer's phone.
     *
     * @param  string  $reference  ours; the bank answers enquiries by it
     * @param  string  $msisdn  2547XXXXXXXX
     * @param  string  $operatorCode  the bus's, exactly as the bank set it up
     */
    public function initiate(string $reference, string $msisdn, int $amount, string $narration, string $operatorCode): ?CoopStkAnswer
    {
        return $this->post('/FT/stk/1.0.0', [
            'MessageReference' => $reference,
            'CallBackUrl' => (string) config('services.coop_stk.callback_url'),
            'OperatorCode' => $operatorCode,
            'TransactionCurrency' => 'KES',
            'MobileNumber' => $msisdn,
            // The bank appends "~<receipt>~<date>" to this and hands it back:
            // a "~" of ours would blur where the receipt starts.
            'Narration' => mb_substr(str_replace('~', '', $narration), 0, 40),
            'Amount' => $amount,
            'MessageDateTime' => Carbon::now('UTC')->format('Y-m-d\TH:i:s.v\Z'),
            'OtherDetails' => [['Name' => 'COOP', 'Value' => $reference]],
        ], 'push');
    }

    /** The outcome of an earlier push, asked of the bank over our authenticated call. */
    public function query(string $reference): ?CoopStkAnswer
    {
        return $this->post('/Enquiry/STK/1.0.0/', ['MessageReference' => $reference], 'enquiry');
    }

    /**
     * Bearer token, reused until shortly before the bank says it expires.
     *
     * Not Cache::remember: a failed request must not be remembered, and the
     * lifetime comes from the bank's own `expires_in`, not a constant.
     */
    public function token(): ?string
    {
        $cached = Cache::get(self::TOKEN_CACHE_KEY);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        try {
            $res = Http::withBasicAuth((string) config('services.coop_stk.consumer_key'), (string) config('services.coop_stk.consumer_secret'))
                ->timeout(15)
                ->acceptJson()
                ->asForm()
                ->post($this->base().'/token', ['grant_type' => 'client_credentials']);
        } catch (Throwable $e) {
            Log::warning('coop stk: token request unreachable', ['error' => $e->getMessage()]);

            return null;
        }

        $token = $res->json('access_token');
        if (! $res->successful() || ! is_string($token) || $token === '') {
            // 403 with an HTML page is the address allowlist; 401 is the key
            // and secret. No body logged: the request carried the credentials.
            Log::warning('coop stk: token refused', ['status' => $res->status()]);

            return null;
        }

        $ttl = max(60, (int) $res->json('expires_in', 3600) - 120);
        Cache::put(self::TOKEN_CACHE_KEY, $token, $ttl);

        return $token;
    }

    private function base(): string
    {
        return rtrim((string) config('services.coop_stk.base_url', 'https://openapi.co-opbank.co.ke'), '/');
    }

    /** @param  array<string, mixed>  $body */
    private function post(string $path, array $body, string $what, bool $retried = false): ?CoopStkAnswer
    {
        $token = $this->token();
        if ($token === null) {
            return null;
        }

        try {
            $res = Http::withToken($token)->timeout(20)->acceptJson()
                ->withBody((string) json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 'application/json')
                ->post($this->base().$path);
        } catch (Throwable $e) {
            Log::warning("coop stk: {$what} unreachable", ['error' => $e->getMessage()]);

            return null;
        }

        if ($res->status() === 401) {
            // A token the bank no longer honours: drop it and ask once more
            // with a fresh one, so an expired token is not the passenger's problem.
            Cache::forget(self::TOKEN_CACHE_KEY);
            if (! $retried) {
                return $this->post($path, $body, $what, true);
            }
        }

        $json = $res->json();
        if (! $res->successful() || ! is_array($json)) {
            Log::warning("coop stk: {$what} refused", [
                'status' => $res->status(),
                'body' => mb_substr((string) $res->body(), 0, 300),
            ]);
        }

        // A readable refusal is an answer (the passenger is told why); an
        // unreadable one, or the bank falling over, is not.
        return is_array($json) && ($res->successful() || $res->clientError()) ? new CoopStkAnswer($res->status(), $json) : null;
    }
}
