<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Mpesa;
use App\Models\Vehicle;
use App\Services\Mpesa\C2bPaymentRecorder;
use App\Services\Mpesa\VehicleByShortCode;
use App\Services\Super\Money\MysqlLegacyPaymentSource;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Recover payments the rate limiter destroyed, from the only copy that survived.
 *
 * On 2026-08-31 the `api` throttle answered 879 M-Pesa confirmations with HTTP
 * 429. ThrottleRequests replies before the route handler, so C2bConfirmationController
 * never ran — not even its first statement, the raw-body MpesaLog write that
 * exists so a dropped payment can be rebuilt. Those payments left NO trace here
 * at all. The legacy Mumbai system received the same confirmations and kept them.
 *
 * TIME-BOXED BY THE CUTOVER. Legacy is switched off this week, and with it the
 * only surviving record of this money.
 *
 * ---------------------------------------------------------------------------
 * WHY NOT legacy:import-money
 *
 * That command exists and would appear to do this. It must not be used here.
 * It preserves legacy ids and calls resequence(), which moves a sequence to
 * MAX(id) — downwards if that is where MAX(id) sits. Collapsing the gap between
 * the live sequence and the legacy id range makes the next Daraja confirmation
 * claim an id an imported row already holds; the insert violates the primary
 * key, the recorder catches it, and Safaricom is still told "Success". The
 * customer is debited and the payment does not exist. Its own docblock says so
 * at length. It is a bulk seeder for a test environment, not a repair.
 *
 * This command preserves nothing. Every recovered payment is inserted as a NEW
 * row through C2bPaymentRecorder — the same path a live confirmation takes — so
 * ids come from the live sequence, attribution uses the live rule, and the daily
 * summary is rolled exactly as it would have been on the day.
 *
 * ---------------------------------------------------------------------------
 * EXISTING ROWS ARE NEVER TOUCHED
 *
 * Receipts we already hold are filtered out BEFORE the recorder sees them, not
 * left to its deduplication. The recorder's duplicate path still rewrites the
 * mpesa row's fields from the payload, and legacy's `mpesas` table has no
 * OrgAccountBalance column — so handing it a known receipt would overwrite the
 * Safaricom balance we backfilled with null, and blind the till-ledger audit on
 * exactly the days it is most needed.
 *
 * Recovered rows carry a null balance, which is honest: we never received that
 * callback, so we never saw the balance. TillLedgerAudit breaks its chain at a
 * null rather than inventing a residue across it.
 *
 * READ-ONLY ON LEGACY. Every statement goes through the legacy_mysql connection,
 * whose grant is SELECT on one database. Nothing here writes to Mumbai.
 */
class BackfillFromLegacy extends Command
{
    protected $signature = 'payments:backfill-from-legacy
        {--from= : Window start, Y-m-d (EAT)}
        {--to= : Window end, Y-m-d (EAT), exclusive}
        {--write : Actually import. Without it this is a dry run and writes nothing}
        {--chunk=1000 : Legacy rows read per batch}
        {--limit=0 : Stop after importing this many (0 = no cap), for a cautious first run}
        {--include-unattributable : Also import payments whose shortcode matches no single bus}';

    protected $description = 'Import payments that exist in the legacy system but never reached this one';

    public function handle(C2bPaymentRecorder $recorder, MysqlLegacyPaymentSource $legacy): int
    {
        if (! $legacy->isAvailable()) {
            $this->error('The legacy connection is not configured — refusing to guess.');
            $this->line('Set LEGACY_DB_HOST / LEGACY_DB_USERNAME / LEGACY_DB_PASSWORD.');

            return self::FAILURE;
        }

        $from = (string) ($this->option('from') ?: now()->subDays(7)->toDateString());
        $to = (string) ($this->option('to') ?: now()->addDay()->toDateString());
        $write = (bool) $this->option('write');
        $chunk = max(1, (int) $this->option('chunk'));
        $cap = max(0, (int) $this->option('limit'));
        $includeUnattributable = (bool) $this->option('include-unattributable');

        $this->info(sprintf('Legacy: %s', $legacy->describe()));
        $this->info(sprintf('Window: %s .. %s (EAT, end exclusive)', $from, $to));
        $this->newLine();

        $scanned = 0;
        $missing = 0;
        $created = 0;
        $unattributed = 0;
        $failed = 0;
        $skipped = 0;
        $skippedValue = 0.0;
        $value = 0.0;
        $dates = [];
        $lastId = 0;
        $lastTime = $from.' 00:00:00';

        while (true) {
            $rows = $this->legacyBatch($from, $to, $lastTime, $lastId, $chunk);
            if ($rows === []) {
                break;
            }

            $tail = end($rows);
            $lastTime = (string) $tail->TransTime;
            $lastId = (int) $tail->id;
            $scanned += count($rows);

            // One indexed lookup per batch decides what is genuinely absent.
            $receipts = array_values(array_filter(array_map(
                static fn ($r) => trim((string) $r->TransID),
                $rows
            )));
            $known = Mpesa::withoutGlobalScopes()
                ->whereIn('TransID', $receipts)
                ->pluck('TransID')
                ->flip();

            foreach ($rows as $row) {
                $receipt = trim((string) $row->TransID);
                if ($receipt === '' || $known->has($receipt)) {
                    continue;
                }

                $missing++;

                // Only money that lands on a bus we can name. Measured over
                // 26-31 Aug, the 15,273 absent payments split three ways:
                // 11,317 (KES 720,261) on tills that resolve to exactly one of
                // our vehicles — real fares, the point of this command; 23
                // (KES 384,175) on collection accounts belonging to no bus,
                // which are the SACCO's nightly sweeps to the bank and are not
                // takings at all; and 3,935 (KES 347,469) on 880100, the NCBA
                // aggregator paybill shared by 34 vehicles, which cannot be
                // attributed to any one of them. Importing the last two would
                // add four thousand rows that no takings figure counts and that
                // the unreconciled view then has to explain forever.
                $vehicle = $this->vehicleFor((string) ($row->BusinessShortCode ?? ''));
                if ($vehicle === null && ! $includeUnattributable) {
                    $skipped++;
                    $skippedValue += (float) $row->TransAmount;

                    continue;
                }

                $value += (float) $row->TransAmount;
                $dates[substr((string) $row->TransTime, 0, 10)] = true;

                if (! $write) {
                    continue;
                }

                $result = $recorder->record(
                    $this->payload($row),
                    fn (string $shortCode, ?string $billRef) => $this->vehicleFor($shortCode)
                );

                if (! $result->ok) {
                    $failed++;

                    continue;
                }

                $created++;
                if ($result->transaction === null || $result->transaction->vehicle_id === null) {
                    $unattributed++;
                }

                if ($cap > 0 && $created >= $cap) {
                    $this->warn("Stopped at the --limit of {$cap}.");
                    break 2;
                }
            }

            $this->output->write('.');
        }

        $this->newLine(2);
        $this->info('Legacy rows scanned      : '.number_format($scanned));
        $this->info('Absent from this system  : '.number_format($missing));
        $this->info('  attributable to a bus  : '.number_format($missing - $skipped).'  (KES '.number_format($value, 2).')');
        if ($skipped > 0) {
            $this->line('  left alone             : '.number_format($skipped).'  (KES '.number_format($skippedValue, 2).')');
            $this->line('    shortcode matches no single bus — bank sweeps and the shared aggregator paybill.');
            $this->line('    These are not takings; --include-unattributable overrides.');
        }

        if (! $write) {
            $this->newLine();
            $this->warn('DRY RUN — nothing was written. Re-run with --write to import.');

            return self::SUCCESS;
        }

        $this->info('Imported                 : '.number_format($created));
        if ($unattributed > 0) {
            $this->warn('  of which unattributed  : '.number_format($unattributed).' (shortcode matches no single vehicle)');
        }
        if ($failed > 0) {
            $this->error('  failed                 : '.number_format($failed).' — see the application log');
        }

        // The recorder rolls each new payment into its day as it goes; a rebuild
        // afterwards is belt and braces, and idempotent by construction.
        foreach (array_keys($dates) as $day) {
            $this->info("Rebuilding summaries for {$day} ...");
            $this->call('app:generate-vehicle-summaries', ['--date' => $day]);
        }

        return self::SUCCESS;
    }

    /**
     * The bus a shortcode belongs to, resolved once per shortcode.
     *
     * The rule is a pure function of the shortcode, and 15,273 payments share
     * 173 of them — so resolving per payment is 15,273 queries where 173 will
     * do, and turns a one-minute dry run into a ten-minute one. Cached per run
     * rather than globally: a till reassigned mid-run is not a case worth
     * serving, and a stale answer here puts money on the wrong matatu.
     *
     * @var array<string, Vehicle|null>
     */
    private array $vehicleCache = [];

    private function vehicleFor(string $shortCode): ?Vehicle
    {
        return $this->vehicleCache[$shortCode]
            ??= VehicleByShortCode::resolve($shortCode);
    }

    /**
     * One page of legacy payments, keyed on (TransTime, id).
     *
     * NOT on id alone, which is the obvious choice and times out. `mpesas` is
     * ~21M rows and the only index that answers this question is
     * mpesas_transtime_index; ordering by id makes MySQL walk the primary key
     * from the beginning, discarding almost the whole table before it reaches a
     * window that sits at the very end of it. Legacy enforces a
     * max_statement_time, so that does not run slowly — it is killed outright
     * (ERROR 3024).
     *
     * TransTime leads so the range is an index scan; id breaks ties within a
     * second so the cursor always advances and cannot loop on a busy timestamp.
     *
     * @return array<int, object>
     */
    private function legacyBatch(string $from, string $to, string $afterTime, int $afterId, int $chunk): array
    {
        // The query builder rather than raw SQL, so identifiers are wrapped by
        // the connection's own grammar. Legacy is MySQL, where `TransID` needs
        // backticks and unquoted would be fine anyway; anything else folds it to
        // lowercase and cannot find the column. Still SELECT and nothing else.
        return DB::connection(MysqlLegacyPaymentSource::CONNECTION)
            ->table('mpesas')
            ->select([
                'id', 'TransID', 'TransAmount', 'TransTime', 'MSISDN', 'FirstName', 'MiddleName',
                'LastName', 'BusinessShortCode', 'TransactionType', 'BillRefNumber',
                'InvoiceNumber', 'ThirdPartyTransID',
            ])
            ->where('TransTime', '>=', $afterTime)
            ->where('TransTime', '<', $to.' 00:00:00')
            ->where(function ($q) use ($afterTime, $afterId): void {
                $q->where('TransTime', '>', $afterTime)
                    ->orWhere(function ($w) use ($afterTime, $afterId): void {
                        $w->where('TransTime', '=', $afterTime)->where('id', '>', $afterId);
                    });
            })
            ->orderBy('TransTime')
            ->orderBy('id')
            ->limit($chunk)
            ->get()
            ->all();
    }

    /**
     * The legacy row rendered as the payload Safaricom would have posted, so the
     * recorder handles it identically to a live confirmation.
     *
     * OrgAccountBalance is deliberately absent: legacy never stored it, and a
     * fabricated value would corrupt the one check that can prove completeness.
     *
     * @return array<string, mixed>
     */
    private function payload(object $row): array
    {
        return [
            'TransID' => (string) $row->TransID,
            'TransAmount' => (string) $row->TransAmount,
            'TransTime' => (string) $row->TransTime,
            'MSISDN' => (string) ($row->MSISDN ?? ''),
            'FirstName' => (string) ($row->FirstName ?? ''),
            'MiddleName' => (string) ($row->MiddleName ?? ''),
            'LastName' => (string) ($row->LastName ?? ''),
            'BusinessShortCode' => (string) ($row->BusinessShortCode ?? ''),
            'TransactionType' => (string) ($row->TransactionType ?? ''),
            'BillRefNumber' => (string) ($row->BillRefNumber ?? ''),
            'InvoiceNumber' => (string) ($row->InvoiceNumber ?? ''),
            'ThirdPartyTransID' => (string) ($row->ThirdPartyTransID ?? ''),
        ];
    }
}
