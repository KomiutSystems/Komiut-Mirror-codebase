<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use App\Models\AuditLog;
use App\Models\PlatformNotification;
use App\Services\Super\Money\CrossSystemReconciler;
use App\Services\Super\Money\LegacyPaymentSource;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * payments:reconcile-legacy — the check that makes the migration measurable.
 *
 * The loss it hunts is invisible to every other signal: Safaricom is acked
 * before the work is done, the recorder catches Throwable, the scheduler exits
 * 0. A payment that never arrives produces an ABSENCE, and only a comparison
 * between the two systems can see an absence.
 *
 * CALIBRATION. These tests are built around the deficit measured read-only on
 * the two live databases on 2026-08-26, 08:00-09:00 EAT:
 *
 *   legacy komiut_latest_app   2,676 payments / KES 169,074.00
 *   this system                2,600 payments / KES 162,024.00
 *   missing here                  76 payments / KES   7,050.00, over 23 minutes
 *   of those, never arrived        76   (no mpesa_logs row)
 *   arrived but not recorded        0
 *   present here, absent there      0
 *
 * and around the clock relationship measured at the same time, which is the one
 * thing in this feature that silently ruins it if it is wrong:
 *
 *   mpesas.TransTime        EAT wall clock, IDENTICAL STRING on both systems
 *   mpesas.created_at       UTC
 *   mpesa_logs.created_at   UTC
 *
 * The legacy side is faked because the suite runs on PostgreSQL and CI has no
 * MySQL (phpunit.xml). That is the right seam anyway: MysqlLegacyPaymentSource
 * is a query with no arithmetic in it, while everything below — bucket diffing,
 * the cause split, the shortcode breakdown, and above all the window's clock —
 * is where a defect would actually hide.
 */
final class CrossSystemReconciliationTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * Install a fake legacy system holding exactly these payments.
     *
     * @param  array<int, array{transId:string, amount:float, shortcode:string, transTime:string}>  $rows
     */
    private function legacyHolds(array $rows): FakeLegacyPaymentSource
    {
        $fake = new FakeLegacyPaymentSource($rows);
        $this->app->instance(LegacyPaymentSource::class, $fake);

        return $fake;
    }

    /**
     * Record a payment in THIS system. $transTime is EAT wall clock, exactly as
     * production stores it.
     */
    private function recordedHere(string $transId, float $amount, string $shortcode, string $transTime): void
    {
        DB::table('mpesas')->insert($this->paymentRow($transId, $amount, $shortcode, $transTime));
    }

    /** @return array<string, string> */
    private function paymentRow(string $transId, float $amount, string $shortcode, string $transTime): array
    {
        return [
            'TransID' => $transId,
            'MSISDN' => '254700000000',
            'TransAmount' => (string) $amount,
            'TransTime' => $transTime,
            'BusinessShortCode' => $shortcode,
            'TransactionType' => 'Customer Merchant Payment',
            // UTC, three hours behind its own TransTime — the real relationship.
            'created_at' => CarbonImmutable::createFromFormat('Y-m-d H:i:s', $transTime, CrossSystemReconciler::PAYMENT_CLOCK)
                ->utc()->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * The raw delivery log C2bConfirmationController writes BEFORE it tries to
     * record anything. Its presence without an `mpesas` row is the whole
     * definition of "arrived but not recorded". created_at is UTC.
     */
    private function deliveryLog(string $transId, string $transTimeEat): void
    {
        DB::table('mpesa_logs')->insert([
            'trans_id' => $transId,
            'log' => json_encode(['TransID' => $transId]),
            'ip_address' => '196.201.214.200',
            'created_at' => CarbonImmutable::createFromFormat('Y-m-d H:i:s', $transTimeEat, CrossSystemReconciler::PAYMENT_CLOCK)
                ->utc()->format('Y-m-d H:i:s'),
            'updated_at' => now(),
        ]);
    }

    /**
     * The measured deficit, at its real scale.
     *
     * 2,676 against 2,600 is not decoration: the check has to survive a window
     * where most minutes agree and a scattered few do not, which is the only
     * shape this failure has ever actually taken. Every payment is KES 50 so the
     * value arithmetic is exact and independently checkable — 76 x 50 = 3,800.
     */
    #[Test]
    public function it_reproduces_the_known_deficit(): void
    {
        $legacyRows = [];
        $localRows = [];
        $missing = [];

        // 2,676 payments spread across the hour, ~45 a minute as in the real data.
        for ($i = 0; $i < 2676; $i++) {
            $transId = sprintf('UHQ%07d', $i);
            $transTime = sprintf('2026-08-26 08:%02d:%02d', intdiv($i, 45), $i % 60);

            // Every 35th payment never made it here, until 76 of them have — the
            // measured count, scattered across the hour rather than bunched, which
            // is the shape the real deficit has.
            $isMissing = $i % 35 === 0 && count($missing) < 76;
            $shortcode = $isMissing ? '880100' : '710051'.($i % 9);

            $legacyRows[] = [
                'transId' => $transId,
                'amount' => 50.0,
                'shortcode' => $shortcode,
                'transTime' => $transTime,
            ];

            if ($isMissing) {
                $missing[] = $transId;

                continue;
            }

            $localRows[] = $this->paymentRow($transId, 50.0, $shortcode, $transTime);
        }

        $this->assertCount(76, $missing, 'fixture must reproduce the measured 76-payment deficit');
        $this->assertCount(2600, $localRows);

        // Chunked: 2,600 single-row inserts would dominate the runtime of the
        // whole suite for no gain.
        foreach (array_chunk($localRows, 500) as $chunk) {
            DB::table('mpesas')->insert($chunk);
        }

        $this->legacyHolds($legacyRows);

        $this->artisan('payments:reconcile-legacy', [
            '--ending' => '2026-08-26 09:00',
            '--minutes' => 60,
        ])->assertExitCode(1);

        $notification = PlatformNotification::where('event', 'payment.cross_system.deficit')->first();
        $this->assertNotNull($notification, 'a deficit must reach the platform console, not just stdout');

        $data = $notification->data;
        $this->assertSame(2676, $data['legacyCount']);
        $this->assertSame(2600, $data['localCount']);
        $this->assertSame(76, $data['missingCount']);
        $this->assertEqualsWithDelta(133800.0, $data['legacyValue'], 0.01);
        $this->assertEqualsWithDelta(130000.0, $data['localValue'], 0.01);
        $this->assertEqualsWithDelta(3800.0, $data['missingValue'], 0.01);

        // No mpesa_logs rows were seeded, so every one is a transport failure —
        // which is what the live data says too.
        $this->assertSame(76, $data['neverArrivedCount']);
        $this->assertSame(0, $data['arrivedNotRecordedCount']);
        $this->assertSame(0, $data['localOnlyCount']);

        // 2.8% missing is well over the 1% critical threshold.
        $this->assertSame('critical', $notification->severity);
    }

    /**
     * THE timezone regression.
     *
     * `now()` is UTC and `TransTime` is EAT, three hours apart. Build the default
     * window on the wrong clock and it slides three hours into the past, onto
     * minutes both systems settled long ago — so the check reports a clean zero
     * forever while money keeps vanishing. It would look exactly like success,
     * which is why this test exists and why it uses the DEFAULT window rather
     * than an explicit --ending.
     */
    #[Test]
    public function the_default_window_is_built_on_the_eat_payment_clock(): void
    {
        // 11:10 EAT.
        Carbon::setTestNow(CarbonImmutable::parse('2026-08-26 08:10:00', 'UTC'));

        // Default lag=10 and minutes=60 put the window at 10:00..11:00 EAT.
        // A UTC-built window would be 07:00..08:00 and would miss this entirely.
        $this->legacyHolds([
            ['transId' => 'UHQINWINDOW', 'amount' => 500.0, 'shortcode' => '880100', 'transTime' => '2026-08-26 10:30:00'],
            ['transId' => 'UHQALSOHERE', 'amount' => 100.0, 'shortcode' => '880100', 'transTime' => '2026-08-26 10:31:00'],
        ]);
        $this->recordedHere('UHQALSOHERE', 100.0, '880100', '2026-08-26 10:31:00');

        $this->artisan('payments:reconcile-legacy', ['--no-alert' => true])
            ->expectsOutputToContain('UHQINWINDOW')
            ->assertExitCode(1);
    }

    /**
     * The split that decides whose problem it is.
     *
     * A payment with a delivery log reached this host and the recording step lost
     * it — our bug. One without never got here at all — transport. Collapsing the
     * two would send every investigation to the same wrong place.
     */
    #[Test]
    public function it_tells_a_lost_recording_apart_from_a_payment_that_never_arrived(): void
    {
        $legacy = [];
        // Six payments in one minute; four recorded here, two not.
        foreach (['A', 'B', 'C', 'D', 'E', 'F'] as $suffix) {
            $legacy[] = [
                'transId' => 'UHQ'.$suffix,
                'amount' => 100.0,
                'shortcode' => '880100',
                'transTime' => '2026-08-26 08:30:00',
            ];
        }
        foreach (['A', 'B', 'C', 'D'] as $suffix) {
            $this->recordedHere('UHQ'.$suffix, 100.0, '880100', '2026-08-26 08:30:00');
        }

        // UHQE got here — its raw body is in mpesa_logs — but no mpesas row
        // exists for it. UHQF left no trace at all.
        $this->deliveryLog('UHQE', '2026-08-26 08:30:00');

        $this->legacyHolds($legacy);

        $this->artisan('payments:reconcile-legacy', [
            '--ending' => '2026-08-26 09:00',
            '--minutes' => 60,
            '--no-alert' => true,
        ])->assertExitCode(1);

        // Read from the audit row rather than the notification: two missing
        // payments sit below the alert threshold on purpose, and the finding must
        // still be recorded in full.
        $report = $this->lastAuditedReport();

        $this->assertSame(2, $report['missingCount']);
        $this->assertSame(1, $report['arrivedNotRecordedCount']);
        $this->assertSame(1, $report['neverArrivedCount']);

        // Both are named, because a count alone is not something anyone can act on.
        $this->assertContains('UHQE', $report['sampleMissingTransIds']);
        $this->assertContains('UHQF', $report['sampleMissingTransIds']);
    }

    /**
     * A window where both systems agree must not touch TransIDs at all.
     *
     * This is what lets the check run every fifteen minutes against a live
     * ~21M-row legacy table: the healthy case, which is almost every run, costs
     * two small indexed aggregates. If a refactor makes the drill unconditional
     * the cost goes up by orders of magnitude on a box still taking real
     * payments, and nothing else would notice.
     */
    #[Test]
    public function a_clean_window_never_drills_into_trans_ids(): void
    {
        $fake = $this->legacyHolds([
            ['transId' => 'UHQSAME1', 'amount' => 30.0, 'shortcode' => '880100', 'transTime' => '2026-08-26 08:05:00'],
            ['transId' => 'UHQSAME2', 'amount' => 70.0, 'shortcode' => '880100', 'transTime' => '2026-08-26 08:06:00'],
        ]);
        $this->recordedHere('UHQSAME1', 30.0, '880100', '2026-08-26 08:05:00');
        $this->recordedHere('UHQSAME2', 70.0, '880100', '2026-08-26 08:06:00');

        $this->artisan('payments:reconcile-legacy', [
            '--ending' => '2026-08-26 09:00',
            '--minutes' => 60,
        ])->assertExitCode(0);

        $this->assertSame(0, $fake->drillCalls, 'a clean window must not pull TransIDs from legacy');
        $this->assertSame(0, PlatformNotification::count(), 'a clean window must not alert');
    }

    /**
     * Payments here that legacy does not have are EXPECTED, not corruption.
     *
     * The cutover moves tills one at a time; a till re-registered to this host
     * stops reaching legacy. Counting those as a discrepancy would make the check
     * alarm louder the better the migration went, which is the fastest way to get
     * a monitor ignored.
     */
    #[Test]
    public function payments_only_this_system_has_are_reported_but_not_counted_as_missing(): void
    {
        $this->legacyHolds([
            ['transId' => 'UHQBOTH', 'amount' => 50.0, 'shortcode' => '880100', 'transTime' => '2026-08-26 08:10:00'],
        ]);
        $this->recordedHere('UHQBOTH', 50.0, '880100', '2026-08-26 08:10:00');
        // A till already migrated: this host has it, legacy never will.
        $this->recordedHere('UHQMIGRATED', 250.0, '6624890', '2026-08-26 08:11:00');

        $this->artisan('payments:reconcile-legacy', [
            '--ending' => '2026-08-26 09:00',
            '--minutes' => 60,
            '--no-alert' => true,
        ])->assertExitCode(0);

        $this->assertSame(0, PlatformNotification::where('event', 'payment.cross_system.deficit')->count());
    }

    /**
     * With no route to legacy the check must say so, not report a reconciled
     * zero. A monitor that cannot tell "nothing is wrong" from "I never looked"
     * is worse than none, because it also stops anyone else looking.
     */
    #[Test]
    public function it_fails_closed_when_the_legacy_connection_is_not_configured(): void
    {
        config(['database.connections.legacy_mysql.host' => null]);

        $this->artisan('payments:reconcile-legacy')
            ->expectsOutputToContain('not configured')
            ->assertExitCode(1);

        // Once a day, so a check nobody wired up cannot pass for one that keeps
        // passing.
        $this->assertSame(1, PlatformNotification::where('event', 'payment.cross_system.unconfigured')->count());
        $this->assertSame(0, PlatformNotification::where('event', 'payment.cross_system.deficit')->count());
    }

    /**
     * The report the last run recorded, read from the audit row.
     *
     * The audit row is written for every finding, while the notification is
     * threshold-gated — so this is the one that always reflects what the check
     * actually saw.
     *
     * @return array<string, mixed>
     */
    private function lastAuditedReport(): array
    {
        $audit = AuditLog::where('action', 'payments.cross_system_deficit')->latest('id')->first();
        $this->assertNotNull($audit, 'a finding must leave an audit row');

        return $audit->data;
    }
}

/**
 * An in-memory legacy system.
 *
 * Mirrors MysqlLegacyPaymentSource's contract exactly — half-open window
 * [from, to), EAT wall-clock strings, minute keys as 'Y-m-d H:i' — because a
 * fake that is more forgiving than the real thing tests nothing. It also counts
 * drill calls, so a test can assert the cheap path stayed cheap.
 */
final class FakeLegacyPaymentSource implements LegacyPaymentSource
{
    public int $drillCalls = 0;

    /** @param array<int, array{transId:string, amount:float, shortcode:string, transTime:string}> $rows */
    public function __construct(private readonly array $rows) {}

    public function isAvailable(): bool
    {
        return true;
    }

    public function minuteBuckets(string $fromEat, string $toEat): array
    {
        $out = [];
        foreach ($this->within($fromEat, $toEat) as $row) {
            $minute = substr($row['transTime'], 0, 16);
            $out[$minute]['count'] = ($out[$minute]['count'] ?? 0) + 1;
            $out[$minute]['value'] = ($out[$minute]['value'] ?? 0.0) + $row['amount'];
        }

        return $out;
    }

    public function payments(string $fromEat, string $toEat, int $limit): array
    {
        $this->drillCalls++;

        $out = [];
        foreach (array_slice($this->within($fromEat, $toEat), 0, $limit) as $row) {
            $out[$row['transId']] = [
                'amount' => $row['amount'],
                'shortcode' => $row['shortcode'],
                'minute' => substr($row['transTime'], 0, 16),
            ];
        }

        return $out;
    }

    public function describe(): string
    {
        return 'in-memory legacy (test)';
    }

    /** @return array<int, array{transId:string, amount:float, shortcode:string, transTime:string}> */
    private function within(string $fromEat, string $toEat): array
    {
        return array_values(array_filter(
            $this->rows,
            fn (array $row): bool => $row['transTime'] >= $fromEat && $row['transTime'] < $toEat,
        ));
    }
}
