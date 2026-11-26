<?php

declare(strict_types=1);

namespace Tests\Feature\Money;

use App\Enums\UserType;
use App\Events\TakingsRecorded;
use App\Models\Transaction;
use App\Models\User;
use App\Providers\BroadcastServiceProvider;
use App\Services\Money\SaccoLiveTakings;
use App\Services\Mpesa\C2bPaymentRecorder;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Contracts\Broadcasting\Factory as BroadcastFactory;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Queues\QueueTestCase;

/**
 * "Collected today" on a SACCO's web dashboard, live: who may hear a SACCO's
 * takings, and that a fare reaches them -- with no person in it -- only while
 * a dashboard is open.
 */
final class SaccoLiveTakingsTest extends QueueTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb' => [
                'driver' => 'reverb', 'key' => 'testing-reverb-key', 'secret' => 'testing-reverb-secret', 'app_id' => 'testing',
                'options' => ['host' => 'localhost', 'port' => 443, 'scheme' => 'https', 'useTLS' => true], 'client_options' => [],
            ],
        ]);
        $this->app->forgetInstance(BroadcastFactory::class);
        $this->app->forgetInstance(BroadcastManager::class);
        $this->app->register(BroadcastServiceProvider::class, true);
    }

    private function subscribe(User $user, int $saccoId): TestResponse
    {
        Sanctum::actingAs($user);

        return $this->postJson('/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => 'private-sacco.'.$saccoId]);
    }

    private function pay(int $vehicleId, string $receipt, ?string $at = null): void
    {
        $result = app(C2bPaymentRecorder::class)->record([
            'TransID' => $receipt, 'MSISDN' => '254798881260', 'TransAmount' => 70, 'TransTime' => $at ?? now()->format('Y-m-d H:i:s'),
            'FirstName' => 'JOYCE', 'MiddleName' => '', 'LastName' => 'MWANGI', 'BusinessShortCode' => '6624890',
            'ThirdPartyTransID' => '', 'InvoiceNumber' => '', 'BillRefNumber' => '', 'TransactionType' => 'Buy Goods Online',
        ], fn () => \App\Models\Vehicle::withoutGlobalScopes()->find($vehicleId));
        $this->assertTrue($result->ok);
    }

    #[Test]
    public function only_the_saccos_own_office_with_money_rights_and_super_admins_may_listen(): void
    {
        $world = $this->makeWorld();
        $sacco = (int) $world['sacco']->id;
        $other = (int) $this->makeSacco()->id;

        $this->subscribe($this->makeUser(['View Summaries'], $world['sacco']), $sacco)->assertOk();
        $this->subscribe($this->makeUser(['View Transactions'], $world['sacco']), $sacco)->assertOk();
        // Another SACCO's office, the same office without money rights, a passenger.
        $this->subscribe($this->makeUser(['View Summaries'], $world['sacco']), $other)->assertForbidden();
        $this->subscribe($this->makeUser(['View Vehicles'], $world['sacco']), $sacco)->assertForbidden();
        $passenger = $this->makeUser([], null);
        $passenger->forceFill(['type' => UserType::Passenger])->save();
        $this->subscribe($passenger->fresh(), $sacco)->assertForbidden();

        $super = $this->makeUser([], null);
        $super->forceFill(['type' => UserType::Superadmin])->save();
        $this->subscribe($super->fresh(), $other)->assertOk();
    }

    #[Test]
    public function a_fare_reaches_an_open_dashboard_at_once_with_no_person_in_it(): void
    {
        Event::fake([TakingsRecorded::class]);
        $world = $this->makeWorld();
        $world['vehicle']->forceFill(['merchant_short_code' => '6624890', 'plate' => 'KDX 392K'])->saveQuietly();
        $this->subscribe($this->makeUser(['View Summaries'], $world['sacco']), (int) $world['sacco']->id)->assertOk();

        $this->pay((int) $world['vehicle']->id, 'UKQ4LIVE01');

        Event::assertDispatched(TakingsRecorded::class, function (TakingsRecorded $e) use ($world) {
            $payload = $e->broadcastWith();

            return $e->broadcastOn()[0]->name === 'private-sacco.'.$world['sacco']->id
                && $e->broadcastAs() === 'takings.recorded'
                && $payload['vehicle_id'] === (int) $world['vehicle']->id && $payload['plate'] === 'KDX 392K'
                && $payload['amount'] === 70.0 && $payload['method'] === 'mpesa' && $payload['recorded_at'] !== null
                && array_keys($payload) === ['transaction_id', 'vehicle_id', 'plate', 'amount', 'method', 'recorded_at'];
        });

        // Cash closed on a trip is takings too.
        Transaction::withoutGlobalScopes()->create(['vehicle_id' => $world['vehicle']->id, 'cash_id' => 5, 'amount' => 100, 'trans_date' => now()]);
        Event::assertDispatched(TakingsRecorded::class, fn (TakingsRecorded $e) => $e->broadcastWith()['method'] === 'cash');
    }

    #[Test]
    public function nothing_is_published_while_no_dashboard_is_open_nor_for_old_money(): void
    {
        Event::fake([TakingsRecorded::class]);
        $world = $this->makeWorld();
        $world['vehicle']->forceFill(['merchant_short_code' => '6624890'])->saveQuietly();

        $this->pay((int) $world['vehicle']->id, 'UKQ4QUIET1');
        Event::assertNotDispatched(TakingsRecorded::class);

        // Watched, but a backfill of last month's money stays silent.
        SaccoLiveTakings::markWatched((int) $world['sacco']->id);
        $this->pay((int) $world['vehicle']->id, 'UKQ4OLD001', now()->subDays(30)->format('Y-m-d H:i:s'));
        Event::assertNotDispatched(TakingsRecorded::class);

        // Money on no bus belongs to no SACCO.
        Transaction::withoutGlobalScopes()->create(['vehicle_id' => null, 'mpesa_id' => 9, 'amount' => 50, 'trans_date' => now()]);
        Event::assertNotDispatched(TakingsRecorded::class);
    }
}
