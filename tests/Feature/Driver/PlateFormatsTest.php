<?php

declare(strict_types=1);

namespace Tests\Feature\Driver;

use App\Enums\UserType;
use App\Models\Sacco;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Driver\VehicleAssignment;
use App\Services\Sql\PlateSql;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Queues\QueueTestCase;

/**
 * A driver signs in by typing the plate painted on the matatu they are standing
 * next to. They do it on a phone keyboard, at a stage, in a hurry, and they are
 * not technical people — so the only thing that decides whether they get to work
 * is the letters and digits, never the punctuation between them.
 *
 * The bug these pin: normalisation was split in half. PHP stripped whitespace
 * with `\s+` while the SQL stripped only the literal space, so a typed hyphen
 * survived on one side of the comparison and not the other. "KDY-759D" could
 * not sign in to the vehicle stored as "KDY 759D", and the driver was told to
 * go and ask their SACCO to register them.
 */
final class PlateFormatsTest extends QueueTestCase
{
    private const ENDPOINT = '/api/v1/auth/driver/login';

    private function makeVehicleWithPlate(Sacco $sacco, string $plate): Vehicle
    {
        $vehicle = $this->makeVehicle($sacco, $this->makeUser([], $sacco), $this->makeSeat());
        $vehicle->update(['plate' => $plate]);

        return $vehicle;
    }

    private function makeDriver(Sacco $sacco, string $phone): User
    {
        $driver = $this->makeUser([], $sacco);
        $driver->forceFill(['type' => UserType::Driver, 'phone' => $phone])->save();

        return $driver;
    }

    /**
     * [stored, typed] — the rule is about punctuation, not about any particular
     * plate, so the STORED side varies too: the standard three-letter form, the
     * four-letter series this fleet actually runs (KTWA/KTWB/KTWC…), a plate
     * stored with the double space the legacy import left behind, and one stored
     * with no separator at all.
     */
    public static function typedVariants(): array
    {
        return [
            'exactly as stored' => ['KDY 759D', 'KDY 759D'],
            'space dropped' => ['KDY 759D', 'KDY759D'],
            'lower case' => ['KDY 759D', 'kdy 759d'],
            'lower, no space' => ['KDY 759D', 'kdy759d'],
            'hyphen for space' => ['KDY 759D', 'KDY-759D'],
            'dot for space' => ['KDY 759D', 'KDY.759D'],
            'slash for space' => ['KDY 759D', 'KDY/759D'],
            'padded' => ['KDY 759D', '  KDY 759D  '],
            'four-letter series, hyphen' => ['KTWA 463G', 'ktwa-463g'],
            'four-letter series, run together' => ['KTWB 109A', 'KTWB109A'],
            'stored with a double space' => ['KTWC  587F', 'ktwc 587f'],
            'stored without a separator' => ['KDQ446R', 'kdq 446 r'],
            'space typed where none stored' => ['KDQ446R', 'KDQ 446R'],
        ];
    }

    #[Test]
    #[DataProvider('typedVariants')]
    public function a_driver_finds_their_matatu_however_they_type_the_plate(string $stored, string $typed): void
    {
        $sacco = $this->makeSacco();
        $vehicle = $this->makeVehicleWithPlate($sacco, $stored);

        $found = app(VehicleAssignment::class)->findByPlate($typed);

        $this->assertNotNull($found, "typing \"{$typed}\" should find the vehicle stored as \"{$stored}\"");
        $this->assertSame($vehicle->id, $found->id);
    }

    /**
     * Four-letter series are real in this fleet (KTWA/KTWB/KTWC …) and the
     * legacy import left non-vehicle rows in the table. Normalisation strips
     * punctuation; it must not assume a plate shape.
     */
    #[Test]
    public function normalisation_does_not_assume_a_plate_shape(): void
    {
        $this->assertSame('KTWA463G', PlateSql::normalise('KTWA  463G'));
        $this->assertSame('KTWA463G', PlateSql::normalise('ktwa-463g'));
        $this->assertSame('PARCELSNAIROBI', PlateSql::normalise('Parcels - (Nairobi)'));
    }

    /**
     * Characters that merely LOOK alike stay distinct. Collapsing O/0 or S/5
     * needs a positional rule this fleet cannot support, and signing a driver in
     * to the wrong matatu is worse than making them retype one character.
     */
    #[Test]
    public function look_alike_characters_are_not_collapsed(): void
    {
        $this->assertNotSame(PlateSql::normalise('KDY 759D'), PlateSql::normalise('KDY 7S9D'));
        $this->assertNotSame(PlateSql::normalise('KDQ 406R'), PlateSql::normalise('KDQ 4O6R'));
    }

    /** The write path must agree with the lookup, or onboarding creates a twin. */
    #[Test]
    public function onboarding_a_differently_typed_plate_reuses_the_vehicle(): void
    {
        $sacco = $this->makeSacco();
        $vehicle = $this->makeVehicleWithPlate($sacco, 'KDY 759D');

        $resolved = app(VehicleAssignment::class)->resolveOrCreate('kdy-759d', $sacco);

        $this->assertSame($vehicle->id, $resolved->id, 'a differently-typed plate must not create a second vehicle');
    }

    /**
     * A plate of pure punctuation normalises to "". Left unguarded that is a
     * live predicate — `= ''` matches any row whose own plate normalises to
     * empty, and the legacy import left non-vehicle rows in this table — so a
     * caller typing "-" would be handed someone else's matatu and, on the login
     * path, run a full crew rotation on it.
     */
    #[Test]
    public function a_plate_with_no_letters_or_digits_matches_nothing(): void
    {
        $sacco = $this->makeSacco();
        $this->makeVehicleWithPlate($sacco, 'KDY 759D');

        foreach (['-', '...', '   ', '((()))'] as $junk) {
            $this->assertNull(
                app(VehicleAssignment::class)->findByPlate($junk),
                "\"{$junk}\" normalises to empty and must not match any vehicle"
            );
        }
    }

    #[Test]
    public function onboarding_refuses_to_register_a_plate_with_no_letters_or_digits(): void
    {
        $sacco = $this->makeSacco();

        $this->expectException(\InvalidArgumentException::class);
        app(VehicleAssignment::class)->resolveOrCreate('-', $sacco);
    }

    #[Test]
    public function the_login_endpoint_rejects_a_junk_plate_before_touching_the_database(): void
    {
        $sacco = $this->makeSacco();
        $this->makeVehicleWithPlate($sacco, 'KDY 759D');
        $this->makeDriver($sacco, '0700000002');

        $this->postJson(self::ENDPOINT, ['phone' => '0700000002', 'plate' => '-'])
            ->assertStatus(400);
    }

    #[Test]
    public function the_login_endpoint_accepts_a_hyphenated_plate(): void
    {
        $sacco = $this->makeSacco();
        $vehicle = $this->makeVehicleWithPlate($sacco, 'KDY 759D');
        $driver = $this->makeDriver($sacco, '0700000001');

        $this->postJson(self::ENDPOINT, ['phone' => '0700000001', 'plate' => 'kdy-759d'])
            ->assertOk();

        $this->assertDatabaseHas('vehicle_users', [
            'user_id' => $driver->id,
            'vehicle_id' => $vehicle->id,
            'status' => true,
        ]);
    }
}
