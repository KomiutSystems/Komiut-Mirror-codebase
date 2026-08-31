<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use App\Models\Mpesa;
use App\Services\Super\Money\TillLedgerAudit;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Queues\QueueTestCase;

/**
 * Checking our records against Safaricom's own arithmetic.
 *
 * Every C2B confirmation carries the till's balance immediately after it, so
 * consecutive confirmations must differ by exactly the amount between them.
 * Anything else is money that entered the till without reaching us.
 *
 * WHY THIS MATTERS MORE THAN THE LEGACY COMPARISON. Comparing against Mumbai
 * only answers "do the two systems agree", and Mumbai is switched off this week.
 * It also cannot see money that is missing from BOTH — which is not theoretical:
 * on 2026-08-30 the legacy comparison reported KES 8,745 missing and this ledger
 * reported KES 20,890.
 *
 * The scenario in `the_production_case` is the real one, reconstructed. It is the
 * anchor for everything else here: on 2026-08-31 KDX 439C lost eleven fares to a
 * rate limiter between 06:37 and 07:44, and both this method and the receipts
 * legacy holds put the loss at exactly KES 850.
 */
final class TillLedgerAuditTest extends QueueTestCase
{
    private const TILL = '4560045';

    private CarbonImmutable $day;

    protected function setUp(): void
    {
        parent::setUp();
        $this->day = CarbonImmutable::parse('2026-08-31 00:00:00');
    }

    /** A confirmation, with the balance Safaricom reported after it. */
    private function paid(string $at, float $amount, ?float $balance, string $till = self::TILL): void
    {
        static $n = 0;
        $n++;

        Mpesa::withoutGlobalScopes()->create([
            'TransID' => 'UHV'.str_pad((string) $n, 7, '0', STR_PAD_LEFT),
            'TransAmount' => (string) $amount,
            'OrgAccountBalance' => $balance,
            'TransTime' => $this->day->toDateString().' '.$at,
            'MSISDN' => '254712345678',
            'FirstName' => 'Joyce',
            'BusinessShortCode' => $till,
            'TransactionType' => 'Customer Merchant Payment',
        ]);
    }

    private function audit(): array
    {
        return app(TillLedgerAudit::class)->audit($this->day, $this->day->addDay());
    }

    #[Test]
    public function a_complete_ledger_reports_nothing_missing(): void
    {
        // Every balance is the one before it plus the amount. Nothing is lost.
        $this->paid('06:00:00', 50, 1000);
        $this->paid('06:05:00', 80, 1080);
        $this->paid('06:10:00', 20, 1100);

        $r = $this->audit();

        $this->assertSame(0.0, $r['lost_fares']);
        $this->assertSame(0, $r['tills_affected']);
        $this->assertSame(3, $r['confirmations']);
    }

    #[Test]
    public function a_payment_that_never_reached_us_shows_as_the_gap_it_left(): void
    {
        // A 70/= fare landed in the till between the second and third
        // confirmations, and we were never told: the balance jumps by 70 more
        // than the payment we can see accounts for.
        $this->paid('06:00:00', 50, 1000);
        $this->paid('06:05:00', 80, 1080);
        $this->paid('06:10:00', 20, 1170);   // 1080 + 70 (unseen) + 20

        $r = $this->audit();

        $this->assertSame(70.0, $r['lost_fares']);
        $this->assertSame(1, $r['tills_affected']);
    }

    #[Test]
    public function the_production_case_reproduces_at_exactly_850(): void
    {
        // KDX 439C, 31 Aug, 06:37-07:44: eleven fares refused by the rate
        // limiter. Legacy holds all eleven; we hold none. Both this method and
        // the receipt-level diff put the loss at KES 850.00.
        $lost = [50, 50, 70, 50, 50, 150, 40, 30, 60, 100, 200];

        $balance = 5000.0;
        $this->paid('06:30:00', 50, $balance);

        $at = 6 * 3600 + 37 * 60;
        foreach ($lost as $missed) {
            $balance += $missed;                       // the fare we never saw
            $balance += 30;                            // the next one we did
            $at += 300;
            $this->paid(gmdate('H:i:s', $at), 30, $balance);
        }

        $r = $this->audit();

        $this->assertSame(850.0, $r['lost_fares'], 'the figure legacy independently confirms');
    }

    #[Test]
    public function a_charge_and_its_reversal_cancel_instead_of_reading_as_loss(): void
    {
        // Production shows adjacent residues like +29.92, -60.00, +30.00 within
        // seconds — a charge settling against the till, netting to nothing.
        // Summing only the positives called that a loss; netting calls it what
        // it is.
        $this->paid('14:23:11', 50, 1000);
        $this->paid('14:29:27', 50, 1079.92);   // +29.92
        $this->paid('14:29:28', 50, 1069.92);   // -60.00
        $this->paid('14:29:38', 50, 1149.92);   // +30.00

        $r = $this->audit();

        $this->assertSame(0.0, $r['lost_fares'], 'a settling charge is not a lost fare');
    }

    #[Test]
    public function a_large_credit_is_surfaced_but_not_counted_as_fares(): void
    {
        // Fares here run 20-200. A single jump of 1,750 across a quiet window is
        // far more likely a bank transfer into the till, and folding it into
        // "lost fares" would triple the headline. It is reported, not silenced.
        $this->paid('10:26:59', 50, 1000);
        $this->paid('11:22:12', 50, 2800);   // +1,750 unexplained

        $r = $this->audit();

        $this->assertSame(0.0, $r['lost_fares']);
        $this->assertSame(1750.0, $r['large_credits']);
        $this->assertSame(1, $r['large_credit_count']);
    }

    #[Test]
    public function tills_are_never_compared_against_each_other(): void
    {
        // Two buses interleaved through the day. Each ledger is its own chain;
        // crossing them would invent enormous residues out of nothing.
        $this->paid('06:00:00', 50, 1000, '4560045');
        $this->paid('06:01:00', 50, 9000, '4560051');
        $this->paid('06:02:00', 80, 1080, '4560045');
        $this->paid('06:03:00', 80, 9080, '4560051');

        $r = $this->audit();

        $this->assertSame(0.0, $r['lost_fares']);
        $this->assertSame(2, $r['tills_checked']);
    }

    #[Test]
    public function a_row_with_no_balance_breaks_the_chain_rather_than_inventing_a_gap(): void
    {
        // Payments recorded before the column existed carry NULL. Comparing
        // across one would manufacture a residue the size of the gap.
        $this->paid('06:00:00', 50, 1000);
        $this->paid('06:05:00', 80, null);
        $this->paid('06:10:00', 20, 1500);

        $r = $this->audit();

        $this->assertSame(0.0, $r['lost_fares'], 'an unknown balance is not evidence of loss');
        $this->assertSame(1, $r['unchecked_rows']);
    }

    #[Test]
    public function a_refund_on_one_till_cannot_mask_a_loss_on_another(): void
    {
        // Netting is per till. A negative net is a refund, not recovered money,
        // and must not be allowed to offset a real loss elsewhere.
        $this->paid('06:00:00', 50, 1000, '4560045');
        $this->paid('06:05:00', 50, 1120, '4560045');   // +70 lost

        $this->paid('06:00:00', 50, 5000, '4560051');
        $this->paid('06:05:00', 50, 4900, '4560051');   // -150 refund

        $r = $this->audit();

        $this->assertSame(70.0, $r['lost_fares'], 'the loss stands on its own');
        $this->assertSame(1, $r['tills_affected']);
    }

    #[Test]
    public function the_window_is_seeded_from_the_previous_day(): void
    {
        // If the first payment of the day is the one that went missing there is
        // nothing to subtract from, so the chain starts at the last confirmation
        // before midnight.
        Mpesa::withoutGlobalScopes()->create([
            'TransID' => 'UHVYESTER1',
            'TransAmount' => '50',
            'OrgAccountBalance' => 1000,
            'TransTime' => $this->day->subDay()->toDateString().' 22:00:00',
            'MSISDN' => '254712345678',
            'FirstName' => 'Joyce',
            'BusinessShortCode' => self::TILL,
        ]);

        $this->paid('06:00:00', 50, 1150);   // 1000 + 100 (unseen) + 50

        $r = $this->audit();

        $this->assertSame(100.0, $r['lost_fares'], 'the first payment of the day is still checkable');
    }

    #[Test]
    public function the_command_fails_loudly_when_money_is_missing(): void
    {
        // Non-zero exit so a cutover checklist can gate on it without parsing.
        $this->paid('06:00:00', 50, 1000);
        $this->paid('06:05:00', 50, 1200);   // +150 lost

        $this->artisan('payments:audit-till-ledger', [
            '--date' => $this->day->toDateString(),
            '--no-alert' => true,
        ])->assertExitCode(1);
    }

    #[Test]
    public function the_command_passes_on_a_clean_day(): void
    {
        $this->paid('06:00:00', 50, 1000);
        $this->paid('06:05:00', 80, 1080);

        $this->artisan('payments:audit-till-ledger', [
            '--date' => $this->day->toDateString(),
            '--no-alert' => true,
        ])->assertExitCode(0);
    }
}
