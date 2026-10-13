<?php

declare(strict_types=1);

namespace App\Services\Mpesa;

use App\Console\Commands\PullMpesaTransactions;
use App\Models\MpesaPaymentSetting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * WHICH OF OUR CONNECTIONS A CONFIRMATION ARRIVED THROUGH.
 *
 * mpesas.mpesa_setting_id is the {id} of the URL Safaricom posted to,
 * /api/confirmation/{id}, and that number belongs to one of TWO id spaces:
 *
 *   ours    a till this system's registrar registered: the vehicle's
 *           till_registered_url ends in /api/confirmation/{id}, for
 *           confirmations received after its till_registered_at;
 *   legacy  every other till. The legacy payments server registered those with
 *           ITS app id, which PullMpesaTransactions::LEGACY_URL_APP maps to a
 *           head-office short code; our connection for that head office is the
 *           one with that business_short_code.
 *
 * The numberings collide -- URL 31 on a legacy till is head office 4064116,
 * our #31 is head office 3020809 -- so reading the id as ours credited one
 * head office's payments to an unrelated connection. Anything that means "our
 * connection" when it reads mpesa_setting_id goes through here.
 *
 * Only each till's CURRENT registration is known (vehicles keeps one URL and
 * time). Payments received under an earlier registration of ours, before the
 * till was moved again, read as legacy ids. That only affects what these
 * pages report about the past -- payments are recorded by store number, never
 * through this map -- and a registration history would fix it if it matters.
 */
final class PaymentConnectionMap
{
    /**
     * till_registered_at is stamped when Safaricom ANSWERS the registration,
     * and the first fares under the new URL can arrive before that.
     */
    private const REGISTRATION_GRACE_SECONDS = 300;

    /**
     * @param  array<string, array{id: int, at: string}>  $registered  store => our id it was registered under, and when (UTC)
     * @param  array<int, ?int>  $legacy  legacy URL id => our connection for its head office (null: we hold none)
     * @param  array<int, string>  $shortCodes  our connection id => its head-office short code
     */
    private function __construct(
        private readonly array $registered,
        private readonly array $legacy,
        private readonly array $shortCodes,
    ) {}

    public static function load(): self
    {
        $settings = MpesaPaymentSetting::withoutGlobalScopes()->orderBy('id')->get(['id', 'business_short_code', 'is_live']);
        $shortCodes = $settings->mapWithKeys(fn ($s) => [(int) $s->id => (string) $s->business_short_code])->all();

        // One connection per short code is enforced for new rows; were an older
        // pair ever to share one, a live app wins, as in PullMpesaTransactions.
        $byHo = $settings->sortBy('is_live')->keyBy(fn ($s) => (string) $s->business_short_code);
        $legacy = [];
        foreach (PullMpesaTransactions::LEGACY_URL_APP as $urlId => $ho) {
            $legacy[(int) $urlId] = $byHo->has($ho) ? (int) $byHo[$ho]->id : null;
        }

        $registered = [];
        foreach (DB::table('vehicles')->whereNotNull('till_registered_url')->whereNotNull('till_registered_at')
            ->whereNotNull('merchant_short_code')->get(['merchant_short_code', 'till_registered_url', 'till_registered_at']) as $v) {
            if (preg_match('#/api/confirmation/(\d+)$#', (string) $v->till_registered_url, $m) && isset($shortCodes[(int) $m[1]])) {
                $registered[(string) $v->merchant_short_code] = [
                    'id' => (int) $m[1],
                    'at' => Carbon::parse($v->till_registered_at, 'UTC')->subSeconds(self::REGISTRATION_GRACE_SECONDS)->format('Y-m-d H:i:s'),
                ];
            }
        }

        return new self($registered, $legacy, $shortCodes);
    }

    /**
     * Our connection one confirmation arrived through, or null when we hold
     * none for the app that registered its till.
     *
     * @param  string|\DateTimeInterface|null  $receivedAt  mpesas.created_at (UTC)
     */
    public function connectionOf(string $store, ?int $urlId, mixed $receivedAt): ?int
    {
        if ($urlId === null) {
            return null;
        }

        $reg = $this->registered[$store] ?? null;
        if ($reg !== null && $reg['id'] === $urlId
            && ($receivedAt === null || Carbon::parse($receivedAt, 'UTC')->format('Y-m-d H:i:s') >= $reg['at'])) {
            return $urlId;
        }

        return $this->legacy[$urlId] ?? null;
    }

    /** The head office a confirmation came in for, whether or not we hold a connection for it. */
    public function headOfficeOf(string $store, ?int $urlId, mixed $receivedAt): ?string
    {
        $id = $this->connectionOf($store, $urlId, $receivedAt);
        if ($id !== null) {
            return $this->shortCodes[$id] ?? null;
        }

        return $urlId === null ? null : (PullMpesaTransactions::LEGACY_URL_APP[$urlId] ?? null);
    }

    /**
     * connectionOf() as SQL over mpesas aliased $m, for grouped queries.
     *
     * @return array{0: string, 1: list<mixed>}
     */
    public function sql(string $m = 'm'): array
    {
        $whens = '';
        $bindings = [];
        foreach ($this->registered as $store => $reg) {
            $whens .= " WHEN {$m}.\"BusinessShortCode\" = ? AND {$m}.mpesa_setting_id = ? AND {$m}.created_at >= ? THEN CAST(? AS BIGINT)";
            array_push($bindings, (string) $store, $reg['id'], $reg['at'], $reg['id']); // an int array key: store numbers are numeric
        }
        foreach ($this->legacy as $urlId => $id) {
            if ($id !== null) {
                $whens .= " WHEN {$m}.mpesa_setting_id = ? THEN CAST(? AS BIGINT)";
                array_push($bindings, $urlId, $id);
            }
        }

        return [$whens === '' ? 'CAST(NULL AS BIGINT)' : "CASE{$whens} END", $bindings];
    }

    /**
     * Confirmations per connection AND till since $since (TransTime, Nairobi
     * wall clock), with the newest of each. One grouped pass over the window
     * for every connection at once -- a week is ~300k rows, so callers cache
     * it -- and per till, so a SACCO is shown only its own buses' traffic.
     *
     * @return array<int, array<string, array{n: int, last: string}>>  connection => store => counts
     */
    public function trafficByStore(string $since): array
    {
        [$case, $bindings] = $this->sql('m');
        $out = [];

        foreach (DB::table('mpesas as m')
            ->where('m.TransTime', '>=', $since)
            ->whereNotNull('m.mpesa_setting_id')
            ->selectRaw("({$case}) AS conn, m.\"BusinessShortCode\" AS store, count(*) AS n, max(m.\"TransTime\") AS last", $bindings)
            ->groupByRaw('1, 2')
            ->get() as $r) {
            if ($r->conn !== null) {
                $out[(int) $r->conn][(string) $r->store] = ['n' => (int) $r->n, 'last' => (string) $r->last];
            }
        }

        return $out;
    }

    /**
     * Each till's newest confirmation since $since, and the connection it came
     * through. One probe per till on ("BusinessShortCode", "TransTime"),
     * newest first -- bounded by $since, because a till paid only through a
     * bank rail has no URL id on any row and would otherwise be read in full.
     *
     * @param  list<string>  $stores
     * @return array<string, array{connection: ?int, head_office: ?string, at: string}>
     */
    public function latestByStore(array $stores, string $since): array
    {
        $out = [];

        foreach ($stores as $store) {
            $store = (string) $store;
            $row = DB::table('mpesas')
                ->where('BusinessShortCode', $store)
                ->where('TransTime', '>=', $since)
                ->whereNotNull('mpesa_setting_id')
                ->orderByDesc('TransTime')->orderByDesc('id')
                ->first(['mpesa_setting_id', 'TransTime', 'created_at']);
            if ($row !== null) {
                $urlId = (int) $row->mpesa_setting_id;
                $out[$store] = [
                    'connection' => $this->connectionOf($store, $urlId, $row->created_at),
                    'head_office' => $this->headOfficeOf($store, $urlId, $row->created_at),
                    'at' => (string) $row->TransTime,
                ];
            }
        }

        return $out;
    }

    /**
     * When a confirmation last arrived through our connection $id, through
     * ANY till: TransTime, Nairobi wall clock, at or after $since. Bounded by
     * $since because a URL id whose rows all belong to another connection is
     * otherwise read back to the beginning.
     *
     * One URL id at a time, so each max() walks the (mpesa_setting_id,
     * "TransTime") index backwards from the newest row and stops at the first
     * one that is really this connection's.
     */
    public function lastPaymentAt(int $id, string $since): ?string
    {
        [$case, $bindings] = $this->sql('m');
        $last = null;

        foreach ($this->urlIdsFor($id) as $urlId) {
            $at = DB::table('mpesas as m')
                ->where('m.mpesa_setting_id', $urlId)
                ->where('m.TransTime', '>=', $since)
                ->whereRaw("({$case}) = ?", [...$bindings, $id])
                ->max('m.TransTime');

            if ($at !== null && ($last === null || (string) $at > $last)) {
                $last = (string) $at;
            }
        }

        return $last;
    }

    /** The URL ids a confirmation for our connection $id can carry. */
    private function urlIdsFor(int $id): array
    {
        $ids = array_keys(array_filter($this->legacy, fn ($c) => $c === $id));
        foreach ($this->registered as $reg) {
            if ($reg['id'] === $id) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }
}
