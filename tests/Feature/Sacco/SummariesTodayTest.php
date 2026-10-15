<?php

declare(strict_types=1);

namespace Tests\Feature\Sacco;

use App\Models\Summary;
use App\Models\Transaction;
use App\Models\Vehicle;
use Carbon\Carbon;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Queues\QueueTestCase;

/** summaries/today: every bus and what it has made today so far, newest payment first. */
final class SummariesTodayTest extends QueueTestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function took(Vehicle $v, float $amount, string $nairobiTime): void
    {
        Summary::create([
            'vehicle_id' => $v->id, 'mpesa_amount' => $amount, 'cash_amount' => 0,
            'mpesa_txn' => 1, 'cash_txn' => 0, 'expense_fee_amount' => '0', 'trans_date' => '2026-10-16',
        ]);
        Transaction::withoutGlobalScopes()->create(['vehicle_id' => $v->id, 'amount' => $amount, 'trans_date' => '2026-10-16 '.$nairobiTime]);
    }

    #[Test]
    public function every_bus_with_its_money_so_far_newest_payment_first(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-16 10:00:00', 'UTC')); // 13:00 in Nairobi
        $world = $this->makeWorld();
        $a = $world['vehicle'];
        $a->forceFill(['plate' => 'KDA 001A'])->save();
        $b = $this->makeVehicle($world['sacco'], $world['owner'], $world['seat']);
        $b->forceFill(['plate' => 'KDB 002B'])->save();
        $c = $this->makeVehicle($world['sacco'], $world['owner'], $world['seat']);
        $c->forceFill(['plate' => 'KDC 003C'])->save();
        $this->took($a, 1000, '12:30:00');
        $this->took($b, 200, '12:50:00');
        $this->took($this->makeWorld()['vehicle'], 5000, '12:55:00'); // another SACCO

        $user = $this->makeUser(['View Summaries'], $world['sacco']);
        $user->forceFill(['sacco_id' => $world['sacco']->id])->save();
        Sanctum::actingAs($user->fresh());

        $res = $this->getJson('/api/v1/auth/summaries/today')->assertOk();

        $this->assertSame(['KDB 002B', 'KDA 001A', 'KDC 003C'], array_column($res->json('vehicles'), 'plate'));
        $this->assertEquals(1200, $res->json('totals.collections'));
        $this->assertSame(3, $res->json('totals.vehicles'));
        $this->assertSame(2, $res->json('totals.collecting'));
        $this->assertSame('2026-10-16T12:50:00+03:00', $res->json('vehicles.0.last_payment_at'));
        $this->assertNull($res->json('vehicles.2.last_payment_at'));

        $this->assertSame(['KDA 001A'], array_column($this->getJson('/api/v1/auth/summaries/today?search=kda001')->json('vehicles'), 'plate'));
    }

    #[Test]
    public function staff_with_no_sacco_see_no_buses(): void
    {
        $world = $this->makeWorld();
        Sanctum::actingAs($this->makeUser(['View Summaries']));

        $this->getJson('/api/v1/auth/summaries/today')->assertOk()->assertJsonPath('vehicles', []);
    }
}
