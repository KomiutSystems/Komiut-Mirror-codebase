<?php

declare(strict_types=1);

namespace App\Services\Mpesa;

use App\Models\Mpesa;
use App\Models\MpesaLog;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Records, from an M-Pesa organisation-portal statement, every completed
 * payment whose confirmation never reached us.
 *
 * WHY. On 2026-09-25 Safaricom stopped delivering C2B confirmations from about
 * 05:30 to 09:00 EAT and delivered no backlog. Daraja's Pull API can recover
 * that only for apps where Safaricom has enabled it -- 41% of NICCO's missing
 * money sat on apps that refuse Pull. The statement a SACCO operator exports
 * from the M-Pesa portal is Safaricom's own record of the till and needs no API
 * at all: receipt, completion time, amount, status, one row per movement.
 *
 * THE FILE. The portal's "transaction" export (.xls, also read as .xlsx/.csv):
 * a header block ("Short Code:", "Time Period:"...) then a row starting
 * "Receipt No." and one row per movement. Only money IN with status Completed
 * is a payment; the settlement sweep ("Merchant Account to Organization
 * Settlement Account") is money out and is never touched.
 *
 * WHAT IS WRITTEN. Exactly what PullTransactionImporter writes, for the same
 * reasons: receipts we hold are left alone (a delivered confirmation carried
 * more -- the running balance, the unmasked name); the rest go through
 * C2bPaymentRecorder, so attribution is the live rule on the statement's short
 * code, the day's summary is rolled, and the realtime broadcast stays silent
 * for old money. The raw statement row is kept in mpesa_logs with ip_address
 * 'mpesa-statement'. The phone is the portal's masked form (070****366); the
 * statement never carries more.
 *
 * THE CLOCK IS CHECKED. The portal prints Nairobi time; the importer compares
 * it with receipts both sides hold and refuses to write if they disagree.
 */
final class StatementImporter
{
    public function __construct(private readonly C2bPaymentRecorder $recorder) {}

    /**
     * @return array{ok:bool, message:string, short_code:?string, holder:?string, plate:?string,
     *   payments:int, held:int, missing:int, missing_kes:float, recorded:int, failed:int, drift:?int,
     *   period:?string, rows:list<array{TransID:string,TransTime:string,TransAmount:string}>}
     */
    public function run(string $path, bool $write): array
    {
        $out = ['ok' => true, 'message' => '', 'short_code' => null, 'holder' => null, 'plate' => null, 'payments' => 0,
            'held' => 0, 'missing' => 0, 'missing_kes' => 0.0, 'recorded' => 0, 'failed' => 0, 'drift' => null, 'period' => null, 'rows' => []];

        try {
            $grid = IOFactory::load($path)->getActiveSheet()->toArray(null, true, false, false);
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'Could not read the file: '.$e->getMessage()] + $out;
        }

        $head = null;
        foreach ($grid as $i => $row) {
            $row = array_map(fn ($v) => trim((string) $v), $row);
            if (($row[0] ?? '') === 'Short Code:') {
                $out['short_code'] = preg_replace('/\D/', '', $row[1] ?? '') ?: null;
            } elseif (($row[0] ?? '') === 'Account Holder:') {
                $out['holder'] = $row[1] ?? null;
            } elseif (($row[0] ?? '') === 'Time Period:') {
                $out['period'] = trim(implode(' ', array_slice($row, 1, 4)));
            } elseif (($row[0] ?? '') === 'Receipt No.') {
                $head = ['index' => $i, 'cols' => array_flip($row)];
                break;
            }
        }
        if ($out['short_code'] === null || $head === null) {
            return ['ok' => false, 'message' => 'Not an M-Pesa portal statement: no "Short Code:" line or no "Receipt No." header.'] + $out;
        }
        foreach (['Receipt No.', 'Completion Time', 'Paid In', 'Transaction Status'] as $need) {
            if (! isset($head['cols'][$need])) {
                return ['ok' => false, 'message' => "The statement has no \"{$need}\" column."] + $out;
            }
        }
        $col = fn (array $row, string $name) => trim((string) ($row[$head['cols'][$name] ?? -1] ?? ''));

        $vehicle = VehicleByShortCode::resolve($out['short_code']);
        $out['plate'] = $vehicle?->plate;

        $payments = [];
        foreach (array_slice($grid, $head['index'] + 1) as $row) {
            $receipt = $col($row, 'Receipt No.');
            $paidIn = (float) str_replace(',', '', $col($row, 'Paid In'));
            if ($receipt === '' || $paidIn <= 0 || strcasecmp($col($row, 'Transaction Status'), 'Completed') !== 0) {
                continue;
            }
            $when = self::nairobi($col($row, 'Completion Time'));
            if ($when === null) {
                continue;
            }
            $payments[$receipt] = ['when' => $when, 'amount' => $paidIn, 'party' => $col($row, 'Other Party Info'),
                'reason' => $col($row, 'Reason Type'), 'raw' => array_map(fn (int $i) => $row[$i] ?? null, array_filter($head['cols'], fn ($k) => $k !== '', ARRAY_FILTER_USE_KEY))];
        }
        $out['payments'] = count($payments);
        if ($payments === []) {
            return $out;
        }

        $held = Mpesa::withoutGlobalScopes()->whereIn('TransID', array_keys($payments))->pluck('TransTime', 'TransID');
        $out['held'] = $held->count();
        $drifts = [];
        foreach ($held as $receipt => $stored) {
            if ($stored !== null) {
                $drifts[] = (int) abs(Carbon::parse($stored)->diffInSeconds($payments[$receipt]['when'], false));
            }
        }
        if ($drifts !== []) {
            sort($drifts);
            $out['drift'] = $drifts[intdiv(count($drifts), 2)];
        }

        foreach ($payments as $receipt => $p) {
            if (! $held->has($receipt)) {
                $out['missing']++;
                $out['missing_kes'] += $p['amount'];
                $out['rows'][] = ['TransID' => $receipt, 'TransTime' => $p['when']->format('Y-m-d H:i:s'), 'TransAmount' => number_format($p['amount'], 2, '.', '')];
            }
        }

        if (! $write || $out['missing'] === 0) {
            return $out;
        }
        if ($out['drift'] !== null && $out['drift'] > PullTransactionImporter::MAX_CLOCK_DRIFT) {
            return ['ok' => false, 'message' => "Statement times differ from ours by {$out['drift']}s on receipts both sides hold; refusing to write."] + $out;
        }
        if ($vehicle === null) {
            return ['ok' => false, 'message' => "Short code {$out['short_code']} matches no single bus; refusing to write money nobody would see."] + $out;
        }

        foreach ($payments as $receipt => $p) {
            if ($held->has($receipt)) {
                continue;
            }
            $this->recordOne($receipt, $p, $out['short_code']) ? $out['recorded']++ : $out['failed']++;
        }

        return $out;
    }

    /** @param array{when:Carbon, amount:float, party:string, reason:string, raw:array} $p */
    private function recordOne(string $receipt, array $p, string $shortCode): bool
    {
        try {
            MpesaLog::create(['trans_id' => $receipt, 'log' => json_encode($p['raw']), 'ip_address' => 'mpesa-statement']);
        } catch (\Throwable $e) {
            Log::error('mpesa statement: could not write MpesaLog', ['trans_id' => $receipt, 'error' => $e->getMessage()]);
        }

        // "070****366 - SAMUEL **** KIMANI": masked phone, then the name with
        // masked middle parts. Masked parts are dropped, not stored as stars.
        [$phone, $name] = array_pad(array_map('trim', explode(' - ', $p['party'], 2)), 2, '');
        $names = array_values(array_filter(preg_split('/\s+/', $name) ?: [], fn ($w) => $w !== '' && ! str_contains($w, '*')));

        $result = $this->recorder->record([
            'TransID' => $receipt,
            'TransAmount' => number_format($p['amount'], 2, '.', ''),
            'TransTime' => $p['when']->format('Y-m-d H:i:s'),
            'MSISDN' => $phone,
            'FirstName' => $names[0] ?? '',
            'MiddleName' => '',
            'LastName' => count($names) > 1 ? (string) end($names) : '',
            'BusinessShortCode' => $shortCode,
            'BillRefNumber' => '',
            'TransactionType' => 'Buy Goods',
            'ThirdPartyTransID' => '',
            'InvoiceNumber' => '',
        ], fn (string $sc, ?string $ref) => VehicleByShortCode::resolve($sc));

        if (! $result->ok) {
            Log::error('mpesa statement: recording failed', ['trans_id' => $receipt, 'error' => $result->error]);
        }

        return $result->ok;
    }

    /** The portal prints "25-09-2026 06:04:11" in Nairobi time; stored as that wall clock. */
    public static function nairobi(string $raw): ?Carbon
    {
        foreach (['d-m-Y H:i:s', 'd/m/Y H:i:s', 'Y-m-d H:i:s', 'd-m-Y H:i'] as $format) {
            $c = \DateTime::createFromFormat('!'.$format, $raw, new \DateTimeZone('UTC'));
            if ($c !== false) {
                return Carbon::instance($c);
            }
        }

        return null;
    }
}
