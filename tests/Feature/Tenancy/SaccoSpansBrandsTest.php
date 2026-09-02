<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Enums\UserType;
use App\Models\Mpesa;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Support\Facades\Context;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Queues\QueueTestCase;

/**
 * A SACCO that runs under two brands is still one SACCO.
 *
 * WHAT HAPPENED. NICCO runs 180 buses across two white-label brands: 126 under
 * Komiut and 54 under 2Safiri, and the split is exactly the financing split —
 * 2Safiri carries the buses Co-op Bank financed, Komiut the NCBA ones. The
 * brands exist so each BANK can see the fleet it financed.
 *
 * BrandScope keys on the host somebody opened, so NICCO's own finance officer,
 * signing in at the Komiut dashboard, was shown 126 of his 180 buses and
 * KES 892,585 of the KES 1,430,420 his SACCO had taken that day. Nothing on the
 * screen said "126 of 180". He reported it as buses missing from the system, and
 * a day went into finding out that the payments were all there and the boundary
 * was the wrong one.
 *
 * THE RULE. Brand is a property of the APP somebody opened — the right wall for
 * a passenger or a driver. It is the wrong wall for anyone whose access is
 * defined by whose money it is, because those callers already carry a tighter
 * boundary: SaccoScope for SACCO staff, FinancierScope for a bank. Brand cutting
 * across either of those defeats the very feature the brands were built for.
 *
 * These tests exist because that is a tenancy change, and the thing a tenancy
 * change must never do is leak. Every case below that widens a view is paired
 * with one proving another SACCO's money stayed invisible.
 */
final class SaccoSpansBrandsTest extends QueueTestCase
{
    /** @return array{0: array, 1: Vehicle, 2: Vehicle} */
    private function saccoAcrossTwoBrands(): array
    {
        $world = $this->makeWorld();

        $onKomiut = $world['vehicle'];
        $onKomiut->brand = 'komiut';
        $onKomiut->save();

        $onSafiri = $this->makeVehicle($world['sacco'], $world['owner'], $world['seat']);
        $onSafiri->brand = 'safiri';
        $onSafiri->save();

        return [$world, $onKomiut->fresh(), $onSafiri->fresh()];
    }

    private function saccoAdmin(array $world): User
    {
        $u = $this->makeUser(['View Transactions'], $world['sacco']);
        $u->forceFill(['type' => UserType::Admin, 'sacco_id' => $world['sacco']->id])->save();

        return $u->fresh();
    }

    private function fare(Vehicle $bus, string $receipt, float $amount): void
    {
        $mpesa = Mpesa::withoutGlobalScopes()->create([
            'TransID' => $receipt,
            'TransAmount' => (string) $amount,
            'TransTime' => now()->toDateTimeString(),
            'MSISDN' => '254712345678',
            'FirstName' => 'Joyce',
            'BusinessShortCode' => '4560045',
        ]);

        Transaction::withoutGlobalScopes()->create([
            'mpesa_id' => $mpesa->id,
            'vehicle_id' => $bus->id,
            'amount' => $amount,
            'trans_date' => now()->toDateTimeString(),
        ]);
    }

    #[Test]
    public function a_sacco_admin_sees_their_whole_fleet_on_either_host(): void
    {
        // THE REPORTED BUG. Signed in on the Komiut host, the 2Safiri half of
        // the SACCO's own fleet was invisible.
        [$world, $komiut, $safiri] = $this->saccoAcrossTwoBrands();
        $this->fare($komiut, 'UHVKOM0001', 100);
        $this->fare($safiri, 'UHVSAF0001', 150);

        Context::add('brand', 'komiut');
        Sanctum::actingAs($this->saccoAdmin($world));

        $body = $this->getJson('/api/v1/auth/transactions?date='.now()->toDateString())
            ->assertOk()->json();

        $this->assertCount(2, $body['transactions'], 'both brands belong to this SACCO');
        $this->assertSame(250.0, (float) $body['mpesa'], 'and the total must cover both');
    }

    #[Test]
    public function another_saccos_money_is_still_invisible(): void
    {
        // The boundary that must NOT move. Widening across brands must not widen
        // across SACCOs — that would be a far worse bug than the one being fixed.
        [$mine, $komiut] = $this->saccoAcrossTwoBrands();
        $theirs = $this->makeWorld();
        $theirBus = $theirs['vehicle'];
        $theirBus->brand = 'komiut';
        $theirBus->save();

        $this->fare($komiut, 'UHVMINE001', 100);
        $this->fare($theirBus->fresh(), 'UHVTHEIRS1', 900);

        Context::add('brand', 'komiut');
        Sanctum::actingAs($this->saccoAdmin($mine));

        $body = $this->getJson('/api/v1/auth/transactions?date='.now()->toDateString())
            ->assertOk()->json();

        $this->assertCount(1, $body['transactions']);
        $this->assertSame(100.0, (float) $body['mpesa'], "another SACCO's takings must not appear");
    }

    #[Test]
    public function the_same_holds_from_the_other_brands_host(): void
    {
        // Symmetry: signing in at 2Safiri must not hide the Komiut half either.
        [$world, $komiut, $safiri] = $this->saccoAcrossTwoBrands();
        $this->fare($komiut, 'UHVKOM0001', 100);
        $this->fare($safiri, 'UHVSAF0001', 150);

        Context::add('brand', 'safiri');
        Sanctum::actingAs($this->saccoAdmin($world));

        $body = $this->getJson('/api/v1/auth/transactions?date='.now()->toDateString())
            ->assertOk()->json();

        $this->assertCount(2, $body['transactions']);
        $this->assertSame(250.0, (float) $body['mpesa']);
    }

    #[Test]
    public function the_vehicles_list_shows_the_whole_fleet_too(): void
    {
        // The transactions screen and the fleet list have to agree, or the money
        // is attributed to buses the same person cannot find.
        [$world] = $this->saccoAcrossTwoBrands();

        Context::add('brand', 'komiut');
        $admin = $this->makeUser(['View Vehicles'], $world['sacco']);
        $admin->forceFill(['type' => UserType::Admin, 'sacco_id' => $world['sacco']->id])->save();
        Sanctum::actingAs($admin->fresh());

        $body = $this->getJson('/api/v1/auth/vehicles')->assertOk()->json();

        $this->assertCount(2, $body['vehicles'], 'a fleet list that hides half the fleet is worse than none');
    }

    #[Test]
    public function a_passenger_is_still_confined_to_the_app_they_opened(): void
    {
        // The case BrandScope was written for, and which must not regress: a
        // saccoless consumer sees only the product in front of them.
        [$world, , $safiri] = $this->saccoAcrossTwoBrands();

        $passenger = $this->makeUser([], null);
        $passenger->forceFill(['type' => UserType::Passenger, 'sacco_id' => null])->save();

        Context::add('brand', 'komiut');

        $visible = Vehicle::query()->pluck('brand')->unique()->values()->all();

        $this->assertNotContains(
            'safiri',
            $visible,
            'brand is still the wall for someone with no ownership boundary of their own'
        );
        $this->assertNotNull($safiri);
        $this->assertNotNull($passenger);
    }

    #[Test]
    public function a_bank_sees_the_buses_it_financed_on_either_host(): void
    {
        // Co-op financed the 2Safiri fleet. A Co-op viewer reaching the platform
        // on the Komiut host would have been shown none of it — brand cutting
        // across the exact view the banks asked for.
        [, , $safiri] = $this->saccoAcrossTwoBrands();
        $safiri->financier = 'coop-bank';
        $safiri->save();

        Context::add('brand', 'komiut');

        $bank = $this->makeUser([], null);
        $bank->forceFill(['type' => UserType::Admin, 'sacco_id' => null, 'financier' => 'coop-bank'])->save();
        $this->actingAs($bank->fresh());

        $plates = Vehicle::query()->pluck('plate')->all();

        $this->assertContains(
            $safiri->plate,
            $plates,
            'a bank sees what it financed, whichever app it signed in through'
        );
    }
}
