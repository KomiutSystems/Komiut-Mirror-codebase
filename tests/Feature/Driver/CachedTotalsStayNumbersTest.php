<?php

declare(strict_types=1);

namespace Tests\Feature\Driver;

use App\Auth\Roles;
use App\Enums\UserType;
use App\Models\Mpesa;
use App\Models\Transaction;
use App\Models\User;
use App\Models\VehicleUser;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\Feature\Queues\QueueTestCase;

/**
 * A cached count must reach the app as a number, not as text.
 *
 * Redis stores a numeric value WITHOUT serialising it, and
 * RedisStore::unserialize returns anything is_numeric() as-is -- so what comes
 * back off a cache hit is the string "4213", not the int the closure returned.
 * `driver/transactions` keeps its total a minute per bus, so the first caller
 * in each minute got an int and everyone after got a string.
 *
 * The driver app casts `total` strictly, so the Earnings screen died on
 * "type 'String' is not a subtype of type 'num?'" and recovered by itself once
 * the key expired -- which is why it read as flaky rather than broken.
 *
 * The array cache driver used in tests does NOT stringify, so the only honest
 * way to pin this is to seed the key the way Redis would answer it.
 */
final class CachedTotalsStayNumbersTest extends QueueTestCase
{
    /** @return array{0: User, 1: array<string, mixed>} */
    private function driverOnABusWithHistory(): array
    {
        $world = $this->makeWorld();
        $driver = $this->makeUser([], $world['sacco']);
        $driver->forceFill(['type' => UserType::Driver, 'sacco_id' => $world['sacco']->id])->save();
        Role::findOrCreate(Roles::DRIVER, 'web');
        $driver->assignRole(Roles::DRIVER);
        VehicleUser::withoutGlobalScopes()->create([
            'user_id' => $driver->id, 'vehicle_id' => $world['vehicle']->id, 'sacco_id' => $world['sacco']->id,
            'status' => true, 'start_date' => now()->subDay(),
        ]);
        foreach (['2026-08-01 09:00', '2026-09-15 09:00'] as $i => $at) {
            $m = Mpesa::withoutGlobalScopes()->create([
                'TransID' => 'CNT'.$i, 'MSISDN' => '254700000000', 'TransAmount' => '100',
                'TransTime' => Carbon::parse($at), 'FirstName' => 'Ivy',
            ]);
            Transaction::withoutGlobalScopes()->create([
                'vehicle_id' => $world['vehicle']->id, 'mpesa_id' => $m->id,
                'amount' => 100, 'trans_date' => Carbon::parse($at),
            ]);
        }

        return [$driver->fresh(), $world];
    }

    #[Test]
    public function a_cached_total_reaches_the_app_as_a_number(): void
    {
        [$driver, $world] = $this->driverOnABusWithHistory();
        Sanctum::actingAs($driver);

        // Exactly what a Redis hit hands back -- 7, not the real count of 2: if this key is ever wrong the closure
        // runs instead and returns 2, so the assertion fails loudly rather
        // than passing for the wrong reason.
        Cache::put('driver:takings-total:'.$world['vehicle']->id, '7', 60);

        $total = $this->getJson('/api/v1/auth/driver/transactions')->assertOk()->json('total');

        $this->assertIsInt($total, 'a cache hit must not put a string on the wire');
        $this->assertSame(7, $total, 'the cached value is what was served');
    }

    #[Test]
    public function a_cached_search_total_reaches_the_app_as_a_number(): void
    {
        [$driver, $world] = $this->driverOnABusWithHistory();
        Sanctum::actingAs($driver);

        // 5, where the real match count is 1 -- same reasoning as above.
        Cache::put('driver:takings-total:'.$world['vehicle']->id.':'.md5('CNT0'), '5', 60);

        $total = $this->getJson('/api/v1/auth/driver/transactions?search=CNT0')->assertOk()->json('total');

        $this->assertIsInt($total, 'the search total takes the same cache path');
        $this->assertSame(5, $total, 'the cached value is what was served');
    }

    #[Test]
    public function last_page_survives_a_cached_total(): void
    {
        [$driver, $world] = $this->driverOnABusWithHistory();
        Sanctum::actingAs($driver);

        Cache::put('driver:takings-total:'.$world['vehicle']->id, '7', 60);

        // ceil() on a string works in PHP, but the app casts this one too.
        $this->assertIsInt(
            $this->getJson('/api/v1/auth/driver/transactions')->assertOk()->json('last_page')
        );
    }
}
