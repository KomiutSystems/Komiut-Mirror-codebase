<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use App\Models\Mpesa;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Queues\QueueTestCase;

/**
 * Recovering Safaricom's running balance for payments recorded before we kept it.
 *
 * The field arrives in every C2B confirmation and was never stored. It survives
 * only inside the raw body C2bConfirmationController writes to `mpesa_logs`
 * before doing anything else — which is exactly the reason that log exists.
 */
final class BackfillOrgAccountBalanceTest extends QueueTestCase
{
    private function payment(string $receipt): Mpesa
    {
        return Mpesa::withoutGlobalScopes()->create([
            'TransID' => $receipt,
            'TransAmount' => '50',
            'TransTime' => now()->toDateTimeString(),
            'MSISDN' => '254712345678',
            'FirstName' => 'Joyce',
            'BusinessShortCode' => '4560045',
        ]);
    }

    private function log(string $receipt, ?string $balance): void
    {
        $body = [
            'TransID' => $receipt,
            'TransAmount' => '50.00',
            'BusinessShortCode' => '4560045',
            'TransTime' => '20260831071500',
        ];
        if ($balance !== null) {
            $body['OrgAccountBalance'] = $balance;
        }

        DB::table('mpesa_logs')->insert([
            'trans_id' => $receipt,
            'log' => json_encode($body),
            'ip_address' => '13.201.15.163',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[Test]
    public function a_stored_body_restores_the_balance(): void
    {
        $p = $this->payment('UHVBACK001');
        $this->log('UHVBACK001', '11210.00');

        $this->assertNull($p->OrgAccountBalance);

        $this->artisan('payments:backfill-balances')->assertExitCode(0);

        $this->assertSame(11210.00, (float) $p->fresh()->OrgAccountBalance);
    }

    #[Test]
    public function a_dry_run_writes_nothing(): void
    {
        $p = $this->payment('UHVBACK001');
        $this->log('UHVBACK001', '11210.00');

        $this->artisan('payments:backfill-balances', ['--dry-run' => true])->assertExitCode(0);

        $this->assertNull($p->fresh()->OrgAccountBalance);
    }

    #[Test]
    public function running_it_twice_changes_nothing_the_second_time(): void
    {
        $p = $this->payment('UHVBACK001');
        $this->log('UHVBACK001', '11210.00');

        $this->artisan('payments:backfill-balances')->assertExitCode(0);
        $this->artisan('payments:backfill-balances')->assertExitCode(0);

        $this->assertSame(11210.00, (float) $p->fresh()->OrgAccountBalance);
    }

    #[Test]
    public function a_balance_already_recorded_is_never_overwritten(): void
    {
        // The live recorder now stores this at the moment of payment. A backfill
        // must never be able to write over a value that came straight from the
        // callback with one reconstructed from a log.
        $p = $this->payment('UHVBACK001');
        $p->OrgAccountBalance = 99999.00;
        $p->save();

        $this->log('UHVBACK001', '11210.00');

        $this->artisan('payments:backfill-balances')->assertExitCode(0);

        $this->assertSame(99999.00, (float) $p->fresh()->OrgAccountBalance);
    }

    #[Test]
    public function a_body_carrying_no_balance_leaves_the_row_null(): void
    {
        // Null means "not told", which is a different and honest claim from 0 —
        // and the audit breaks its chain at a null rather than comparing across.
        $p = $this->payment('UHVBACK001');
        $this->log('UHVBACK001', null);

        $this->artisan('payments:backfill-balances')->assertExitCode(0);

        $this->assertNull($p->fresh()->OrgAccountBalance);
    }

    #[Test]
    public function a_log_with_no_matching_payment_is_ignored(): void
    {
        $this->log('UHVORPHAN1', '500.00');

        $this->artisan('payments:backfill-balances')->assertExitCode(0);

        $this->assertSame(0, Mpesa::withoutGlobalScopes()->where('TransID', 'UHVORPHAN1')->count());
    }

    #[Test]
    public function unparseable_bodies_do_not_stop_the_run(): void
    {
        DB::table('mpesa_logs')->insert([
            'trans_id' => 'UHVBROKEN1',
            'log' => 'not json at all',
            'ip_address' => '13.201.15.163',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $p = $this->payment('UHVBACK001');
        $this->log('UHVBACK001', '11210.00');

        $this->artisan('payments:backfill-balances')->assertExitCode(0);

        $this->assertSame(11210.00, (float) $p->fresh()->OrgAccountBalance);
    }
}
