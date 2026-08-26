<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The two legacy money commands must not be reachable by accident.
 *
 * Neither is scheduled any more, so the only way either fires is a person
 * typing it — a tab-completion, a runbook line pasted into the wrong shell, a
 * deploy script that grew an extra command. Both then write to the live money
 * tables, and neither failure is loud:
 *
 *   legacy:import-money  rewinds the id sequences (reopening a collision that
 *                        loses M-Pesa confirmations) and nulls cash_id on every
 *                        transaction in the table, live driver fares included.
 *   copy:mpesa           usually makes the remote replay from the start of
 *                        history, and re-adds every replayed row to a vehicle's
 *                        day summary with no already-recorded guard.
 *
 * --confirm-legacy-migration is the whole guard, so these tests are about the
 * refusal rather than the import: what matters is that a run without the flag
 * changes nothing at all.
 */
final class LegacyMoneyCommandGuardsTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, string> gz exports written by a test, removed afterwards. */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }

        parent::tearDown();
    }

    /**
     * A minimal but genuinely importable export: one M-Pesa row carrying a
     * legacy id well past anything this database has issued, which is the shape
     * that makes the sequence rewind dangerous in the first place.
     */
    private function export(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'legacy-money-');
        $this->tempFiles[] = $path;

        $handle = gzopen($path, 'wb');
        gzwrite($handle, "###MPESAS\n");
        gzwrite($handle, json_encode([
            'id' => 19_000_001,
            'TransID' => 'SFT0LEGACY1',
            'MSISDN' => '254700000001',
            'TransAmount' => '150',
            'TransTime' => '2024-03-04 07:15:00',
            'FirstName' => 'LEGACY',
            'BusinessShortCode' => '4321075',
            'TransactionType' => 'Customer Merchant Payment',
            'created_at' => '2024-03-04 07:15:00',
        ])."\n");
        gzclose($handle);

        return $path;
    }

    /**
     * A cash fare of the kind DriverTripController::confirmCash() writes today:
     * a transaction pointing at a cashes row. Inserted through the query builder
     * rather than the model so no tenant global scope is involved — repair()
     * uses the query builder too, and would find this row either way.
     */
    private function liveCashFare(): int
    {
        return (int) DB::table('transactions')->insertGetId([
            'vehicle_id' => null,
            'cash_id' => 4242,
            'amount' => 300,
            'trans_date' => '2026-08-26 09:00:00',
            'created_at' => '2026-08-26 09:00:00',
            'updated_at' => '2026-08-26 09:00:00',
        ]);
    }

    #[Test]
    public function import_money_refuses_to_write_without_the_confirmation_flag(): void
    {
        $id = $this->liveCashFare();

        $this->artisan('legacy:import-money', ['--file' => $this->export()])
            ->expectsOutputToContain('--confirm-legacy-migration')
            ->assertExitCode(1);

        $this->assertSame(0, DB::table('mpesas')->count(), 'a refused run must import nothing');

        // The unscoped repair() is the part that cannot be undone: cash_id is the
        // only thing marking this row as cash, and nothing in it restores that.
        $this->assertSame(
            4242,
            (int) DB::table('transactions')->where('id', $id)->value('cash_id'),
            'a refused run must leave a live driver cash fare pointing at its cashes row',
        );
    }

    #[Test]
    public function import_money_names_both_hazards_when_it_refuses(): void
    {
        // The refusal has to be actionable on its own: whoever typed the command
        // is unlikely to go and read the class docblock before reaching for the
        // flag, so the reason has to be in front of them at the point of refusal.
        $this->artisan('legacy:import-money', ['--file' => $this->export()])
            ->expectsOutputToContain('sequences')
            ->expectsOutputToContain('cash_id')
            ->assertExitCode(1);
    }

    #[Test]
    public function a_dry_run_still_works_without_the_flag(): void
    {
        $id = $this->liveCashFare();

        // --dry-run returns before repair() and resequence() and writes nothing,
        // so it stays one command away. Gating the safe inspection path too would
        // only teach people to append the flag by reflex.
        $this->artisan('legacy:import-money', ['--file' => $this->export(), '--dry-run' => true])
            ->assertExitCode(0);

        $this->assertSame(0, DB::table('mpesas')->count(), 'a dry run writes nothing');
        $this->assertSame(4242, (int) DB::table('transactions')->where('id', $id)->value('cash_id'));
    }

    #[Test]
    public function import_money_writes_once_the_flag_is_given(): void
    {
        $this->artisan('legacy:import-money', [
            '--file' => $this->export(),
            '--confirm-legacy-migration' => true,
        ])->assertExitCode(0);

        // Legacy ids are preserved — that is what makes the sequence handling in
        // resequence() matter, and it is what a deliberate run is for.
        $this->assertSame(1, DB::table('mpesas')->where('id', 19_000_001)->count());
    }

    #[Test]
    public function copy_mpesa_refuses_before_it_looks_at_anything_else(): void
    {
        // Deliberately set: without the flag the refusal must be about the flag,
        // not about a missing legacy host that someone could "fix" by exporting
        // LEGACY_BASE_URL and trying again.
        config(['services.legacy.base_url' => 'https://legacy.example.invalid']);

        $this->artisan('copy:mpesa')
            ->expectsOutputToContain('--confirm-legacy-migration')
            ->assertExitCode(1);

        $this->assertSame(0, DB::table('mpesas')->count());
        $this->assertSame(0, DB::table('transactions')->count());
    }

    #[Test]
    public function copy_mpesa_still_fails_closed_on_an_unset_legacy_host_with_the_flag(): void
    {
        // The flag confirms intent; it does not stand in for configuration. The
        // unset host is what stops a confirmed run from pulling customer payment
        // records out of the live legacy box, and it must still hold.
        config(['services.legacy.base_url' => null]);

        $this->artisan('copy:mpesa', ['--confirm-legacy-migration' => true])
            ->expectsOutputToContain('LEGACY_BASE_URL')
            ->assertExitCode(1);
    }
}
