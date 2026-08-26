<?php

declare(strict_types=1);

namespace App\Services\Super\Money;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Compares the payments legacy holds against the payments this system holds, and
 * says which ones are missing here and why.
 *
 * ---------------------------------------------------------------------------
 * WHY THIS EXISTS
 * ---------------------------------------------------------------------------
 *
 * Nothing else in the migration is measurable without it. The loss is invisible
 * precisely because nothing compares the two systems: every individual component
 * reports success. Safaricom is told "Success" for every confirmation, including
 * ones this host never received (C2bConfirmationController answers before doing
 * the work, deliberately). The recorder catches Throwable. The scheduler exits 0.
 * A payment that never arrives produces no error anywhere, on either system — it
 * produces an absence, and absences are only visible by comparison.
 *
 * ---------------------------------------------------------------------------
 * THE CLOCK. READ THIS BEFORE CHANGING ANY TIMESTAMP HERE.
 * ---------------------------------------------------------------------------
 *
 * A timezone mistake in this file does not produce a wrong number. It produces a
 * check that is permanently alarming or permanently silent, and both of those get
 * switched off within a week — which is strictly worse than never having built it.
 *
 * Measured read-only against BOTH live databases on 2026-08-26, not assumed:
 *
 *   mpesas.TransTime   EAT (UTC+3) WALL CLOCK ON BOTH SYSTEMS, stored naive.
 *                      Legacy MySQL `datetime`, this system PostgreSQL
 *                      `timestamp without time zone`. The SAME payment carries
 *                      the SAME string on both sides — TransID UHQCV3Z0NG reads
 *                      '2026-08-26 08:59:59' in legacy and '2026-08-26 08:59:59'
 *                      here. There is NO offset to apply between them, and
 *                      applying one is the classic way to break this check.
 *
 *   mpesas.created_at  UTC. Same row, three hours apart from its own TransTime:
 *   mpesa_logs.created_at
 *                      UHQCV3Z0NG has created_at '2026-08-26 06:00:00' against
 *                      TransTime '2026-08-26 08:59:59'. The app runs
 *                      APP_TIMEZONE=UTC and PostgreSQL TimeZone=UTC.
 *
 * So one table holds both clocks, three hours apart, in adjacent columns. The
 * consequence that matters: `now()` is UTC, and comparing it to TransTime without
 * converting compares a UTC instant against an EAT wall clock. The window would
 * silently slide three hours into the past, land on a period both systems settled
 * long ago, and report a clean zero forever while money kept going missing. That
 * is the permanently-silent failure, and it would look exactly like success.
 *
 * Hence: the window is built in EAT and handed around as EAT wall-clock strings
 * (PAYMENT_CLOCK below), and the ONLY place a conversion happens is where we
 * touch mpesa_logs.created_at, which is genuinely UTC. Africa/Nairobi has no DST,
 * so the offset is a fixed +03:00 and there is no seasonal edge to get wrong.
 *
 * ---------------------------------------------------------------------------
 * HOW IT LOOKS, AND WHY IN THAT ORDER
 * ---------------------------------------------------------------------------
 *
 *   1. Per-MINUTE buckets on both sides. Cheap — an indexed range scan returning
 *      ~60 rows an hour — so the healthy case, which is almost every run, costs
 *      two small queries and touches no TransIDs at all.
 *   2. Only for the minutes whose counts disagree, pull the TransIDs from both
 *      sides and diff them. This is what turns "a number is wrong" into a list of
 *      payments somebody can actually chase.
 *   3. Look the missing TransIDs up in mpesa_logs to split them:
 *
 *        NEVER ARRIVED         no log row. The confirmation never reached this
 *                              host: a transport/registration problem.
 *        ARRIVED NOT RECORDED  the raw body is in mpesa_logs but no `mpesas` row
 *                              exists. It got here and the recording step lost
 *                              it: our bug, and a much more urgent one.
 *
 *      That split is the entire diagnostic value of the check, and it is only
 *      possible because C2bConfirmationController writes mpesa_logs BEFORE
 *      anything else and outside its try block — see its docblock, which says in
 *      as many words that this is the table proving traffic has started arriving.
 *
 * Calibrated against the known deficit: 2026-08-26 08:00-09:00 EAT, legacy
 * 2,676 / KES 169,074 against 2,600 / KES 162,024 here = 76 missing / KES 7,050,
 * spread over 23 minutes, all 76 of them NEVER ARRIVED, 0 arrived-not-recorded,
 * and 0 payments present here but absent from legacy.
 *
 * READ-ONLY on both sides. It never writes to legacy (see MysqlLegacyPaymentSource)
 * and never writes here either, so it is safe to run repeatedly, on overlapping
 * windows, and from more than one place.
 */
final class CrossSystemReconciler
{
    /**
     * The clock `mpesas.TransTime` is kept in, on BOTH systems. See the class
     * docblock — this is a measured fact about the data, not a display preference.
     */
    public const PAYMENT_CLOCK = 'Africa/Nairobi';

    /**
     * How far back of the window start to look for a delivery log.
     *
     * Only a performance bound: the match is on trans_id, and this just keeps the
     * scan off the whole table. Generous on purpose and open-ended at the top,
     * because the two misclassifications are not equally bad. Calling an arrived
     * payment "never arrived" points the investigation at Safaricom and the
     * network when the bug is ours — so when in doubt, look further for the log.
     */
    private const LOG_LOOKBACK_HOURS = 1;

    public function __construct(private readonly LegacyPaymentSource $legacy) {}

    public function reconcile(CarbonImmutable $fromEat, CarbonImmutable $toEat, int $drillLimit = 20000): ReconciliationReport
    {
        $from = $fromEat->format('Y-m-d H:i:s');
        $to = $toEat->format('Y-m-d H:i:s');

        $legacyBuckets = $this->legacy->minuteBuckets($from, $to);
        $localBuckets = $this->localMinuteBuckets($from, $to);

        // The minutes worth paying for a TransID-level diff.
        //
        // Count AND value, not count alone. Equal counts do not mean the same
        // payments: during the per-till cutover a minute can perfectly well hold
        // one payment that failed to reach us and one from an already-migrated
        // till that legacy will never see. The counts cancel, and a count-only
        // comparison would skip that minute and call the window clean while a
        // real payment was missing from it. Amounts cancelling exactly as well is
        // far less likely, so testing both closes most of that hole for the price
        // of a float comparison.
        //
        // Compared in BOTH directions, because local-only rows are expected
        // during the cutover and a check that could not see them would report
        // migration progress as corruption.
        $deficitMinutes = [];
        foreach (array_keys($legacyBuckets + $localBuckets) as $minute) {
            $legacyCount = $legacyBuckets[$minute]['count'] ?? 0;
            $localCount = $localBuckets[$minute]['count'] ?? 0;
            $legacyValue = $legacyBuckets[$minute]['value'] ?? 0.0;
            $localValue = $localBuckets[$minute]['value'] ?? 0.0;

            // Half a cent: these are money totals summed as DECIMAL(15,2) and
            // then cast to float, so an exact !== would flag rounding noise as a
            // discrepancy on every run.
            if ($legacyCount !== $localCount || abs($legacyValue - $localValue) > 0.005) {
                $deficitMinutes[$minute] = true;
            }
        }

        $totals = [
            'legacyCount' => array_sum(array_column($legacyBuckets, 'count')),
            'legacyValue' => array_sum(array_column($legacyBuckets, 'value')),
            'localCount' => array_sum(array_column($localBuckets, 'count')),
            'localValue' => array_sum(array_column($localBuckets, 'value')),
        ];

        if ($deficitMinutes === []) {
            return new ReconciliationReport(
                fromEat: $from,
                toEat: $to,
                legacyCount: (int) $totals['legacyCount'],
                legacyValue: (float) $totals['legacyValue'],
                localCount: (int) $totals['localCount'],
                localValue: (float) $totals['localValue'],
                missing: [],
                localOnlyCount: 0,
                localOnlyValue: 0.0,
                deficitMinutes: 0,
                // Counted even on a clean window. Bodies arriving that we cannot
                // parse are worth surfacing on their own account, and leaving the
                // field at a hard-coded 0 here would make it mean two different
                // things depending on which branch produced the report.
                unattributableLogs: $this->unattributableLogCount($fromEat, $toEat),
                truncated: false,
                legacyDescription: $this->legacy->describe(),
            );
        }

        // One span covering every disagreeing minute, then filtered back down in
        // PHP. A query per minute would be up to 60 round trips to another region
        // for the same rows a single indexed range already returns.
        $minutes = array_keys($deficitMinutes);
        sort($minutes);
        $spanFrom = CarbonImmutable::createFromFormat('Y-m-d H:i', $minutes[0], self::PAYMENT_CLOCK)->startOfMinute();
        $spanTo = CarbonImmutable::createFromFormat('Y-m-d H:i', $minutes[array_key_last($minutes)], self::PAYMENT_CLOCK)
            ->startOfMinute()->addMinute();

        $legacyPayments = $this->legacy->payments(
            $spanFrom->format('Y-m-d H:i:s'),
            $spanTo->format('Y-m-d H:i:s'),
            $drillLimit,
        );
        $localPayments = $this->localPayments(
            $spanFrom->format('Y-m-d H:i:s'),
            $spanTo->format('Y-m-d H:i:s'),
            $drillLimit,
        );

        $truncated = count($legacyPayments) >= $drillLimit || count($localPayments) >= $drillLimit;

        $legacyPayments = array_filter($legacyPayments, fn (array $p): bool => isset($deficitMinutes[$p['minute']]));
        $localPayments = array_filter($localPayments, fn (array $p): bool => isset($deficitMinutes[$p['minute']]));

        $missingIds = array_keys(array_diff_key($legacyPayments, $localPayments));
        $localOnly = array_diff_key($localPayments, $legacyPayments);

        $arrived = $this->deliveredTransIds($missingIds, $fromEat);

        $missing = [];
        foreach ($missingIds as $id) {
            $missing[] = [
                'transId' => $id,
                'amount' => $legacyPayments[$id]['amount'],
                'shortcode' => $legacyPayments[$id]['shortcode'],
                'minute' => $legacyPayments[$id]['minute'],
                'arrived' => isset($arrived[$id]),
            ];
        }

        return new ReconciliationReport(
            fromEat: $from,
            toEat: $to,
            legacyCount: (int) $totals['legacyCount'],
            legacyValue: (float) $totals['legacyValue'],
            localCount: (int) $totals['localCount'],
            localValue: (float) $totals['localValue'],
            missing: $missing,
            localOnlyCount: count($localOnly),
            localOnlyValue: round(array_sum(array_column($localOnly, 'amount')), 2),
            deficitMinutes: count($deficitMinutes),
            unattributableLogs: $this->unattributableLogCount($fromEat, $toEat),
            truncated: $truncated,
            legacyDescription: $this->legacy->describe(),
        );
    }

    /**
     * @return array<string, array{count:int, value:float}>
     */
    private function localMinuteBuckets(string $fromEat, string $toEat): array
    {
        $grammar = DB::connection()->getQueryGrammar();
        $amount = $grammar->wrap('TransAmount');

        $rows = DB::table('mpesas')
            ->where('TransTime', '>=', $fromEat)
            ->where('TransTime', '<', $toEat)
            ->groupByRaw($this->minuteExpression())
            ->selectRaw($this->minuteExpression().' as bucket')
            ->selectRaw('COUNT(*) as n')
            // TransAmount is a varchar on both systems. NULLIF guards the empty
            // string, which PostgreSQL will not cast to a number — the exact
            // failure documented in phpunit.xml as the reason this suite runs on
            // PostgreSQL rather than sqlite.
            ->selectRaw("COALESCE(SUM(CAST(NULLIF($amount, '') AS DECIMAL(15,2))), 0) as value")
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row->bucket] = ['count' => (int) $row->n, 'value' => (float) $row->value];
        }

        return $out;
    }

    /**
     * @return array<string, array{amount:float, shortcode:string, minute:string}>
     */
    private function localPayments(string $fromEat, string $toEat, int $limit): array
    {
        // DB::table, not the Mpesa model, and deliberately. Mpesa carries
        // BelongsToSacco and BelongsToFinancier; those scopes are no-ops without
        // an authenticated user and so happen to be harmless in a console run,
        // but a reconciliation that silently answered "for the current tenant"
        // would under-report and look healthy doing it. A system-level count must
        // not be able to be scoped by accident.
        $rows = DB::table('mpesas')
            ->where('TransTime', '>=', $fromEat)
            ->where('TransTime', '<', $toEat)
            ->orderBy('TransTime')
            ->limit(max(1, $limit))
            ->get(['TransID', 'TransAmount', 'BusinessShortCode', 'TransTime']);

        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row->TransID] = [
                'amount' => (float) $row->TransAmount,
                'shortcode' => (string) ($row->BusinessShortCode ?? ''),
                // Substring rather than a date function: TransTime is naive EAT on
                // both sides, so the first 16 characters ARE the minute bucket, in
                // the same format the legacy side produced. Re-parsing it into a
                // date object would only add a chance to attach a timezone to it.
                'minute' => substr((string) $row->TransTime, 0, 16),
            ];
        }

        return $out;
    }

    /**
     * Which of these TransIDs have a raw delivery log — i.e. did reach this host.
     *
     * @param  array<int, string>  $transIds
     * @return array<string, true>
     */
    private function deliveredTransIds(array $transIds, CarbonImmutable $fromEat): array
    {
        if ($transIds === []) {
            return [];
        }

        // THE conversion. mpesa_logs.created_at is UTC while the window is EAT —
        // see the class docblock. Nothing else in this file crosses clocks.
        $floor = $fromEat->utc()->subHours(self::LOG_LOOKBACK_HOURS)->format('Y-m-d H:i:s');

        $found = [];
        // Chunked: an outage window can produce thousands of missing ids, and a
        // single IN () list that long is refused by some drivers and merely
        // terrible on the rest.
        foreach (array_chunk($transIds, 1000) as $chunk) {
            $rows = DB::table('mpesa_logs')
                ->whereIn('trans_id', $chunk)
                ->where('created_at', '>=', $floor)
                ->pluck('trans_id');

            foreach ($rows as $id) {
                $found[(string) $id] = true;
            }
        }

        return $found;
    }

    /**
     * Delivery logs in this window that carry no TransID at all.
     *
     * C2bConfirmationController writes `(string) ($fields['TransID'] ?? '')`, so a
     * confirmation whose body it could not parse still leaves a row — with an
     * empty trans_id, which no lookup above can ever match. Those payments would
     * be counted as NEVER ARRIVED when in fact they arrived and were unreadable.
     *
     * Reported rather than corrected for, because the honest statement is that a
     * non-zero number here makes never-arrived an upper bound and points a human
     * at the raw bodies.
     */
    private function unattributableLogCount(CarbonImmutable $fromEat, CarbonImmutable $toEat): int
    {
        return (int) DB::table('mpesa_logs')
            ->where('created_at', '>=', $fromEat->utc()->format('Y-m-d H:i:s'))
            ->where('created_at', '<', $toEat->utc()->format('Y-m-d H:i:s'))
            ->where(function ($q): void {
                $q->whereNull('trans_id')->orWhere('trans_id', '');
            })
            ->count();
    }

    /**
     * Minute bucket as 'Y-m-d H:i', in the local database's dialect.
     *
     * Production and the suite are both PostgreSQL (phpunit.xml explains at
     * length why). The other arms exist so that a mismatch between the two would
     * be a wrong answer rather than a fatal error at 3am.
     */
    private function minuteExpression(): string
    {
        $column = DB::connection()->getQueryGrammar()->wrap('TransTime');

        return match (DB::connection()->getDriverName()) {
            'pgsql' => "to_char($column, 'YYYY-MM-DD HH24:MI')",
            'mysql', 'mariadb' => "DATE_FORMAT($column, '%Y-%m-%d %H:%i')",
            'sqlite' => "strftime('%Y-%m-%d %H:%M', $column)",
            default => throw new RuntimeException(
                'Cross-system reconciliation has no minute expression for driver '.DB::connection()->getDriverName().'.'
            ),
        };
    }
}
