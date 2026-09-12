<?php

declare(strict_types=1);

namespace Tests\Feature\Driver;

use App\Enums\UserType;
use App\Models\Sacco;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Driver\PlateNotAvailable;
use App\Services\Driver\VehicleAssignment;
use Illuminate\Support\Facades\Context;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Queues\QueueTestCase;

/**
 * A driver signs into their SACCO's bus whichever brand it runs under.
 *
 * NICCO runs 126 buses as Komiut and 54 as 2Safiri -- the Co-op financed ones.
 * Its drivers crew both. On 2026-09-12 the crew of KDS 194X (2Safiri) tried the
 * Komiut driver app three times with the right phone and the right plate and
 * were told "No active assignment for this phone and vehicle. Ask your SACCO to
 * register you on this matatu." The SACCO had registered them in March 2025.
 *
 * The login request is unauthenticated, so BrandScope applied to its vehicle
 * lookup and hid every bus of the other brand. Nothing else in the driver's day
 * is brand-scoped: once signed in they carry a sacco_id, which BrandScope treats
 * as the tighter boundary and steps aside. The one request that could not see
 * the bus was the one deciding whether the shift happened.
 *
 * The SACCO is the boundary. These pin that it is the only one at sign-in, that
 * it still holds across brands, and that onboarding -- which can CREATE a
 * vehicle and must do so in the app's own brand -- did not move with it.
 */
final class DriverLoginCrossesBrandsTest extends QueueTestCase
{
    private const ENDPOINT = '/api/v1/auth/driver/login';

    /** The request arrives on the `testing` brand; this bus runs under another. */
    private const OTHER_BRAND = 'safiri';

    private function driver(Sacco $sacco, string $phone): User
    {
        $driver = $this->makeUser([], $sacco);
        $driver->forceFill(['type' => UserType::Driver, 'phone' => $phone])->save();

        return $driver;
    }

    private function busUnderOtherBrand(Sacco $sacco, string $plate): Vehicle
    {
        $vehicle = $this->makeVehicle($sacco, $this->makeUser([], $sacco), $this->makeSeat());
        $vehicle->forceFill(['plate' => $plate, 'brand' => self::OTHER_BRAND])->save();

        return $vehicle;
    }

    #[Test]
    public function a_driver_signs_into_their_saccos_bus_of_the_other_brand(): void
    {
        // The SACCO row itself sits on the other brand too -- the mirror of the
        // incident (a 2Safiri sign-in at a SACCO whose row is branded komiut),
        // which is what makes the `sacco` on the response worth asserting: the
        // eager load is a fresh query that gets BrandScope back unless told not
        // to, and used to answer null there.
        $sacco = $this->makeSacco();
        $sacco->forceFill(['brand' => self::OTHER_BRAND])->save();
        $driver = $this->driver($sacco, '0724160975');
        $bus = $this->busUnderOtherBrand($sacco, 'KDS 194X');

        $response = $this->postJson(self::ENDPOINT, ['phone' => '0724160975', 'plate' => 'KDS 194X']);

        $response->assertOk()
            ->assertJsonPath('vehicle.id', $bus->id)
            ->assertJsonPath('vehicle.brand', self::OTHER_BRAND)
            ->assertJsonPath('vehicle.sacco.id', $sacco->id);
        $this->assertNotEmpty($response->json('access_token'));

        $this->assertDatabaseHas('vehicle_users', [
            'user_id' => $driver->id,
            'vehicle_id' => $bus->id,
            'sacco_id' => $sacco->id,
            'status' => true,
            'end_date' => null,
        ]);
    }

    #[Test]
    public function the_sacco_wall_still_holds_across_brands(): void
    {
        // Looking past brand must not look past the SACCO. Another SACCO's bus
        // on another brand is refused exactly as it was when it shared ours.
        $mine = $this->makeSacco();
        $theirs = $this->makeSacco();
        $driver = $this->driver($mine, '0724160975');
        $theirBus = $this->busUnderOtherBrand($theirs, 'KDS 194X');

        $this->postJson(self::ENDPOINT, ['phone' => '0724160975', 'plate' => 'KDS 194X'])
            ->assertStatus(403)
            ->assertJsonPath('error', 'This vehicle belongs to another SACCO');

        $this->assertDatabaseMissing('vehicle_users', [
            'user_id' => $driver->id,
            'vehicle_id' => $theirBus->id,
        ]);
    }

    #[Test]
    public function a_plate_nobody_runs_is_still_unknown_whatever_the_brand(): void
    {
        $sacco = $this->makeSacco();
        $this->driver($sacco, '0724160975');
        $this->busUnderOtherBrand($sacco, 'KDS 194X');

        $this->postJson(self::ENDPOINT, ['phone' => '0724160975', 'plate' => 'KDS 194Y'])
            ->assertStatus(401);
    }

    #[Test]
    public function onboarding_still_refuses_a_plate_held_under_another_brand(): void
    {
        // resolveOrCreate() can create a vehicle, and a vehicle must be created
        // in the brand of the app the agent is holding. It therefore keeps the
        // brand-scoped lookup: the other brand's bus is not "ours to return" and
        // not "free to create" either.
        $sacco = $this->makeSacco();
        $this->busUnderOtherBrand($sacco, 'KDS 194X');

        // Not an HTTP request, so stand where the onboarding controller would:
        // inside a resolved brand. Without one BrandScope does not apply at all.
        Context::add('brand', 'testing');
        try {
            $this->expectException(PlateNotAvailable::class);

            app(VehicleAssignment::class)->resolveOrCreate('KDS 194X', $sacco);
        } finally {
            Context::forget('brand');
        }
    }
}
