<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Enums\UserType;
use App\Models\Mpesa;
use App\Models\Sacco;
use App\Models\Transaction;
use App\Models\Vehicle;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Queues\QueueTestCase;

/**
 * mpesa/stats' ten most recent payments are taken from each of the tenant's
 * buses' ten newest, instead of hashing the tenant's whole matched history
 * every 60s poll (6-13s for SACCOs other than NICCO). Same ten payments.
 */
final class MpesaStatsRecentTest extends QueueTestCase
{
    private function bus(Sacco $sacco, ?string $financier = null): Vehicle
    {
        $vehicle = $this->makeVehicle($sacco, $this->makeUser([], $sacco), $this->makeSeat());
        $vehicle->update(['financier' => $financier]);

        return $vehicle;
    }

    private function paid(?Vehicle $bus, string $receipt, string $at): void
    {
        $m = Mpesa::withoutGlobalScopes()->create(['TransID' => $receipt, 'MSISDN' => '254700111222', 'TransAmount' => 10,
            'TransTime' => Carbon::parse($at), 'FirstName' => 'Test', 'LastName' => 'Payer', 'BusinessShortCode' => '5557936']);
        if ($bus !== null) {
            Transaction::withoutGlobalScopes()->create(['mpesa_id' => $m->id, 'vehicle_id' => $bus->id, 'amount' => 10, 'trans_date' => Carbon::parse($at)]);
        }
    }

    /** @return list<string> */
    private function recent(): array
    {
        return array_column($this->getJson('/api/v1/auth/mpesa/stats')->assertOk()->json('recent_transactions'), 'trans_id');
    }

    #[Test]
    public function a_sacco_sees_its_ten_newest_payments_across_all_its_buses(): void
    {
        $sacco = $this->makeSacco();
        [$busy, $quiet] = [$this->bus($sacco), $this->bus($sacco)];
        for ($i = 0; $i < 12; $i++) {
            $this->paid($busy, sprintf('BUSY%02d', $i), sprintf('2026-10-23 08:%02d:00', $i));   // 08:00 .. 08:11
        }
        $this->paid($quiet, 'QUIET1', '2026-10-23 08:05:30');   // falls inside the newest ten
        $this->paid($quiet, 'QUIET0', '2026-10-01 09:00:00');   // old: outside it
        $this->paid($this->bus($this->makeSacco()), 'OTHERSACCO', '2026-10-23 09:00:00');
        $this->paid(null, 'UNMATCHED', '2026-10-23 09:30:00');
        Sanctum::actingAs($this->makeUser(['View Transactions'], $sacco));

        $this->assertSame(
            ['BUSY11', 'BUSY10', 'BUSY09', 'BUSY08', 'BUSY07', 'BUSY06', 'QUIET1', 'BUSY05', 'BUSY04', 'BUSY03'],
            $this->recent()
        );
    }

    #[Test]
    public function a_bank_sees_only_its_own_buses_payments(): void
    {
        $sacco = $this->makeSacco();
        $this->paid($this->bus($sacco, 'NCBA'), 'NCBA1', '2026-10-23 08:00:00');
        $this->paid($this->bus($sacco, 'coop-bank'), 'COOP1', '2026-10-23 08:01:00');
        $bank = $this->makeUser(['View Transactions']);
        $bank->forceFill(['financier' => 'NCBA', 'sacco_id' => null])->save();
        Sanctum::actingAs($bank->fresh());

        $this->assertSame(['NCBA1'], $this->recent());
    }

    #[Test]
    public function a_super_admin_still_sees_payments_nothing_matched(): void
    {
        $this->paid($this->bus($this->makeSacco()), 'MATCHED', '2026-10-23 08:00:00');
        $this->paid(null, 'UNMATCHED', '2026-10-23 08:30:00');
        $admin = $this->makeUser(['View Transactions']);
        $admin->forceFill(['type' => UserType::Superadmin])->save();
        Sanctum::actingAs($admin);

        $this->assertSame(['UNMATCHED', 'MATCHED'], $this->recent());
    }

    #[Test]
    public function the_recent_list_is_read_per_bus(): void
    {
        $sacco = $this->makeSacco();
        $this->paid($this->bus($sacco), 'ONE', '2026-10-23 08:00:00');
        Sanctum::actingAs($this->makeUser(['View Transactions'], $sacco));
        $sql = [];
        DB::listen(function ($q) use (&$sql) {
            if (str_contains($q->sql, 'from "mpesas"') && str_contains($q->sql, 'limit 10')) {
                $sql[] = $q->sql;
            }
        });

        $this->recent();

        $this->assertNotEmpty($sql);
        $this->assertStringContainsString('cross join lateral', $sql[0]);
    }
}
