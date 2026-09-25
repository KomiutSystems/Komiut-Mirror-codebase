<?php

declare(strict_types=1);

namespace App\Services\Mpesa;

use App\Models\Mpesa;
use App\Models\MpesaLog;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Log;

/**
 * Asks Safaricom for every C2B payment on one till in a window, and records the
 * ones we never received.
 *
 * WHY THIS EXISTS. On 2026-09-25, between about 05:30 and 09:00 EAT, Safaricom
 * stopped delivering C2B confirmations to the fleet. Nothing on our side failed
 * -- the load balancer had two healthy targets and answered every request 200 --
 * the callbacks simply never came, and no backlog was delivered afterwards. One
 * bus alone (KDV 672W, till 3702865) had 137 completed payments on its M-Pesa
 * statement and 34 in our books. A confirmation that is never sent leaves no
 * trace anywhere we can see; the Pull Transactions API is the only Daraja call
 * that answers "what did this till actually receive?" from Safaricom's side.
 *
 * WHAT IT WRITES, AND HOW. Nothing is written for a receipt we already hold --
 * those are filtered out BEFORE the recorder sees them, for the same reason
 * BackfillFromLegacy does it: the recorder's duplicate path rewrites fields
 * from the payload, and a pulled row carries less than a confirmation did (no
 * running balance, a masked phone). Everything else goes through
 * C2bPaymentRecorder, the path a live confirmation takes, so ids come from the
 * live sequence, attribution is the live rule (VehicleByShortCode), the daily
 * summary is rolled exactly as on the day, and the realtime broadcast stays
 * silent for anything older than half an hour. The raw pulled row is kept in
 * mpesa_logs with ip_address 'daraja-pull', so every recovered payment says
 * where it came from.
 *
 * THE CLOCK IS CHECKED, NOT ASSUMED. Pull returns `trxDate` as an ISO string
 * whose zone designator Safaricom does not apply consistently, and our
 * TransTime column is Nairobi wall-clock. Every pull overlaps receipts we
 * already hold, so the importer compares their times and refuses to write when
 * they disagree -- a three-hour shift would put money on the wrong business day.
 */
final class PullTransactionImporter
{
    /** Pages beyond this are a runaway, not a busy till. */
    private const MAX_PAGES = 200;

    /** Seconds two clocks may differ on the same receipt and still agree. */
    public const MAX_CLOCK_DRIFT = 120;

    public function __construct(private readonly C2bPaymentRecorder $recorder) {}

    /**
     * @return array{
     *   ok: bool, message: string, pulled: int, held: int, missing: int, recorded: int,
     *   failed: int, skipped: array<string,int>, drift: ?int, missing_kes: float,
     *   rows: list<array{TransID:string, TransTime:string, TransAmount:string}>
     * }
     */
    public function run(DarajaClient $client, string $shortCode, ?int $settingId, CarbonInterface $from, CarbonInterface $to, bool $write, bool $utc = false): array
    {
        $out = ['ok' => true, 'message' => '', 'pulled' => 0, 'held' => 0, 'missing' => 0, 'recorded' => 0,
            'failed' => 0, 'skipped' => [], 'drift' => null, 'missing_kes' => 0.0, 'rows' => []];

        $rows = [];
        for ($page = 0, $offset = 0; $page < self::MAX_PAGES; $page++) {
            $res = $client->pullQuery($shortCode, $from->format('Y-m-d H:i:s'), $to->format('Y-m-d H:i:s'), $offset);
            if ($res === null) {
                // Mid-till, a page we could not read means the set is
                // incomplete: nothing is written for this till this run.
                return ['ok' => false, 'message' => 'Safaricom did not answer (network); nothing written for this till'] + $out;
            }
            $code = (string) ($res['ResponseCode'] ?? '');
            $batch = self::rowsOf($res);
            if ($batch === []) {
                // The end of the set -- or, on the FIRST page, the answer an
                // unregistered shortcode also gives ("No records found or
                // Organization Name not available", 1001; 500 "Failed to
                // retrieve"). Reported, never taken for a clean quiet till.
                if ($rows === []) {
                    $out['message'] = trim(($res['ResponseMessage'] ?? $res['errorMessage'] ?? ('HTTP '.($res['_http'] ?? '?'))).' (code '.$code.')');
                }
                break;
            }
            array_push($rows, ...$batch);
            // Paged by offset until an empty page: the page size is Safaricom's
            // to choose (their own example pages by 100).
            $offset += count($batch);
        }

        $payments = [];
        foreach ($rows as $raw) {
            $row = array_change_key_case((array) $raw, CASE_LOWER);
            $type = strtolower((string) ($row['transactiontype'] ?? ''));
            $amount = (float) ($row['amount'] ?? 0);
            // Customer payments only. Settlement sweeps and org transfers are not
            // fares, and the live path keeps them out of takings the same way.
            if ($amount <= 0 || ($type !== '' && ! str_contains($type, 'c2b'))) {
                $out['skipped'][$type !== '' ? $type : 'non-positive amount'] = ($out['skipped'][$type !== '' ? $type : 'non-positive amount'] ?? 0) + 1;

                continue;
            }
            $receipt = trim((string) ($row['transactionid'] ?? ''));
            if ($receipt === '') {
                $out['skipped']['no receipt'] = ($out['skipped']['no receipt'] ?? 0) + 1;

                continue;
            }
            $payments[$receipt] = $row;
        }
        $out['pulled'] = count($payments);
        if ($payments === []) {
            return $out;
        }

        $held = Mpesa::withoutGlobalScopes()->whereIn('TransID', array_keys($payments))->pluck('TransTime', 'TransID');
        $out['held'] = $held->count();

        // The clock check, on receipts both sides hold.
        $drifts = [];
        foreach ($held as $receipt => $storedTime) {
            $pulled = self::wallClock((string) ($payments[$receipt]['trxdate'] ?? ''), $utc);
            if ($pulled !== null && $storedTime !== null) {
                $drifts[] = abs(Carbon::parse($storedTime)->diffInSeconds($pulled, false));
            }
        }
        if ($drifts !== []) {
            sort($drifts);
            $out['drift'] = (int) $drifts[intdiv(count($drifts), 2)];
        }

        foreach ($payments as $receipt => $row) {
            if ($held->has($receipt)) {
                continue;
            }
            $when = self::wallClock((string) ($row['trxdate'] ?? ''), $utc);
            $out['missing']++;
            $out['missing_kes'] += (float) $row['amount'];
            $out['rows'][] = ['TransID' => $receipt, 'TransTime' => $when?->format('Y-m-d H:i:s') ?? '?', 'TransAmount' => (string) $row['amount']];
        }

        if (! $write || $out['missing'] === 0) {
            return $out;
        }
        if ($out['drift'] === null) {
            return ['ok' => false, 'message' => 'No overlapping receipt to check the clock against; refusing to write. Widen the window so it includes payments we already hold.'] + $out;
        }
        if ($out['drift'] > self::MAX_CLOCK_DRIFT) {
            return ['ok' => false, 'message' => "Pulled times differ from ours by {$out['drift']}s on receipts both sides hold; refusing to write (try --utc)."] + $out;
        }

        foreach ($payments as $receipt => $row) {
            if ($held->has($receipt)) {
                continue;
            }
            $result = $this->recordOne($receipt, $row, $shortCode, $settingId, $utc);
            $result ? $out['recorded']++ : $out['failed']++;
        }

        return $out;
    }

    /** @param array<string,mixed> $row lower-cased pulled row */
    private function recordOne(string $receipt, array $row, string $shortCode, ?int $settingId, bool $utc): bool
    {
        try {
            MpesaLog::create(['trans_id' => $receipt, 'log' => json_encode($row), 'ip_address' => 'daraja-pull']);
        } catch (\Throwable $e) {
            Log::error('daraja pull: could not write MpesaLog', ['trans_id' => $receipt, 'error' => $e->getMessage()]);
        }

        $names = preg_split('/\s+/', trim((string) ($row['sender'] ?? ''))) ?: [];
        $type = strtolower((string) ($row['transactiontype'] ?? ''));

        $result = $this->recorder->record([
            'TransID' => $receipt,
            'TransAmount' => (string) $row['amount'],
            'TransTime' => self::wallClock((string) ($row['trxdate'] ?? ''), $utc)?->format('Y-m-d H:i:s'),
            'MSISDN' => (string) ($row['msisdn'] ?? ''),
            'FirstName' => (string) ($names[0] ?? ''),
            'MiddleName' => count($names) > 2 ? implode(' ', array_slice($names, 1, -1)) : '',
            'LastName' => count($names) > 1 ? (string) end($names) : '',
            'BusinessShortCode' => $shortCode,
            'BillRefNumber' => (string) ($row['billreference'] ?? ''),
            'TransactionType' => str_contains($type, 'pay-bill') ? 'Pay Bill' : (str_contains($type, 'buy-goods') ? 'Buy Goods' : (string) ($row['transactiontype'] ?? '')),
            'ThirdPartyTransID' => '',
            'InvoiceNumber' => '',
            'MpesaSettingId' => $settingId,
        ], fn (string $sc, ?string $ref) => VehicleByShortCode::resolve($sc));

        if (! $result->ok) {
            Log::error('daraja pull: recording failed', ['trans_id' => $receipt, 'error' => $result->error]);
        }

        return $result->ok;
    }

    /** @return list<mixed> the transaction rows, however Safaricom nested them */
    public static function rowsOf(array $res): array
    {
        $r = $res['Response'] ?? [];
        if (! is_array($r) || $r === []) {
            return [];
        }
        // Documented shape: Response is a list holding ONE list of rows.
        if (array_is_list($r) && is_array($r[0] ?? null) && array_is_list($r[0])) {
            return array_values(array_merge(...array_filter($r, 'is_array')));
        }

        return array_is_list($r) ? $r : [$r];
    }

    /**
     * Nairobi wall-clock for a pulled `trxDate`. By default the zone designator
     * is ignored and the digits are read as Nairobi time; with $utc they are
     * read as UTC and shifted. Which one is right is decided by the clock check
     * in run(), never by trusting the designator.
     */
    public static function wallClock(string $raw, bool $utc): ?Carbon
    {
        if (! preg_match('/(\d{4})-?(\d{2})-?(\d{2})[T ]?(\d{2}):?(\d{2}):?(\d{2})/', $raw, $m)) {
            return null;
        }
        $digits = "{$m[1]}-{$m[2]}-{$m[3]} {$m[4]}:{$m[5]}:{$m[6]}";

        return $utc
            ? Carbon::parse($digits, 'UTC')->setTimezone('Africa/Nairobi')->shiftTimezone('UTC')
            : Carbon::parse($digits, 'UTC');
    }
}
