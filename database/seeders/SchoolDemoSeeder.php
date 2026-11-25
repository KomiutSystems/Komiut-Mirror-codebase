<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Auth\Roles;
use App\Enums\SaccoClaimStatus;
use App\Enums\UserType;
use App\Models\Gender;
use App\Models\Place;
use App\Models\Route;
use App\Models\RouteStage;
use App\Models\Sacco;
use App\Models\SaccoRoute;
use App\Models\SaccoUser;
use App\Models\Seat;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleFollower;
use App\Models\VehicleUser;
use App\Services\School\FollowCode;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

/**
 * A demo school for building and clicking through school bus tracking
 * locally (docs/school-bus-tracking.md): one school with an admin login, two
 * buses with follow codes, a driver on bus 1, a morning route with three
 * stops, and a parent following bus 1 from the middle stop.
 *
 * Run it on purpose -- `php artisan db:seed --class=SchoolDemoSeeder` -- it is
 * not part of DatabaseSeeder. Idempotent: every row is found by a natural key
 * first, and a bus keeps the follow code it was given on the first run, so
 * re-running never invalidates a code someone already copied.
 *
 * Every login shares the password below. No child is named anywhere: the
 * product stores none.
 */
class SchoolDemoSeeder extends Seeder
{
    private const PASSWORD = 'password';

    private const BRAND = 'komiut';

    private const SCHOOL = 'Greenfield Demo Academy';

    /** [place name, latitude, longitude, km from the first stop]: Kahawa West to the school. */
    private const STOPS = [
        ['Kahawa West Junction', -1.1856, 36.9038, 0.0],
        ['Kahawa Sukari Gate', -1.1932, 36.9353, 3.6],
        ['Greenfield Demo Academy Gate', -1.2010, 36.9520, 5.8],
    ];

    public function run(): void
    {
        // The School Admin role and its bundle (RoleSeeder is idempotent).
        $this->call(RoleSeeder::class);

        $gender = Gender::firstOrCreate(['name' => 'Male'], ['status' => true]);

        // Brand stamped explicitly: the BelongsToBrand auto-stamp only fires
        // inside a request with a brand context, never in a CLI seed.
        $school = Sacco::withoutGlobalScopes()->updateOrCreate(
            ['name' => self::SCHOOL],
            [
                'email' => 'office@greenfield.school.test',
                'phone' => '0700000200',
                'status' => 1,
                'brand' => self::BRAND,
                'kind' => Sacco::KIND_SCHOOL,
                'claim_status' => SaccoClaimStatus::Claimed,
                'source' => 'seeded',
                'verified_at' => now(),
            ],
        );

        $admin = $this->user('admin@greenfield.school.test', 'Greenfield', 'Office', '0711000201', $gender->id, $school->id, UserType::Admin);
        $admin->syncRoles([Role::findByName(Roles::SCHOOL_ADMIN, 'web')]);

        $driver = $this->user('driver@greenfield.school.test', 'Demo', 'School Driver', '0711000202', $gender->id, $school->id, UserType::Driver);
        $driver->syncRoles([Role::findByName(Roles::DRIVER, 'web')]);

        $parent = $this->user('parent@greenfield.school.test', 'Demo', 'Parent', '0711000203', $gender->id, null, UserType::Passenger);

        foreach ([$admin, $driver] as $member) {
            SaccoUser::updateOrCreate(
                ['user_id' => $member->id, 'sacco_id' => $school->id],
                ['start_date' => now()->subMonth(), 'status' => true, 'created_by' => $admin->id],
            );
        }

        $seat = Seat::firstOrCreate(
            ['name' => '33-Seater School Bus'],
            ['seats' => 33, 'rows' => 11, 'columns' => 3, 'status' => true],
        );

        $buses = [];
        foreach ([['KSB 101A', 'GB1'], ['KSB 102A', 'GB2']] as [$plate, $fleetNo]) {
            $bus = Vehicle::withoutGlobalScopes()->updateOrCreate(
                ['plate' => $plate],
                [
                    'fleet_no' => $fleetNo,
                    'sacco_id' => $school->id,
                    'user_id' => $admin->id,
                    'seat_id' => $seat->id,
                    'status' => true,
                    'brand' => self::BRAND,
                ],
            );
            if ($bus->follow_code === null) {
                FollowCode::issue($bus);
            }
            $buses[] = $bus;
        }

        VehicleUser::updateOrCreate(
            ['user_id' => $driver->id, 'vehicle_id' => $buses[0]->id],
            ['sacco_id' => $school->id, 'start_date' => now()->subWeek(), 'status' => true],
        );

        $places = [];
        foreach (self::STOPS as [$name, $lat, $lng]) {
            $places[] = Place::updateOrCreate(
                ['name' => $name],
                ['county_name' => 'Nairobi', 'latitude' => $lat, 'longitude' => $lng, 'status' => 1],
            );
        }

        $route = Route::withoutGlobalScopes()->updateOrCreate(
            ['name' => 'Greenfield morning run', 'sacco_id' => $school->id],
            ['from_id' => $places[0]->id, 'to_id' => end($places)->id, 'status' => 1],
        );

        foreach (self::STOPS as $i => [, $lat, $lng, $km]) {
            RouteStage::updateOrCreate(
                ['route_id' => $route->id, 'place_id' => $places[$i]->id],
                ['latitude' => $lat, 'longitude' => $lng, 'distance' => $km, 'sequence' => $i + 1, 'status' => 1],
            );
        }

        // The school runs the route; it charges no fare.
        SaccoRoute::withoutGlobalScopes()->updateOrCreate(
            ['sacco_id' => $school->id, 'route_id' => $route->id],
            ['user_id' => $admin->id, 'amount' => 0, 'min_amount' => 0, 'status' => 1],
        );

        VehicleFollower::updateOrCreate(
            ['user_id' => $parent->id, 'vehicle_id' => $buses[0]->id],
            ['stop_place_id' => $places[1]->id],
        );
    }

    private function user(string $email, string $first, string $last, string $phone, int $genderId, ?int $saccoId, UserType $type): User
    {
        return User::updateOrCreate(
            ['email' => $email],
            [
                'firstname' => $first,
                'lastname' => $last,
                'phone' => $phone,
                'dob' => '1990-01-01',
                'password' => self::PASSWORD, // hashed by the model's cast
                'gender_id' => $genderId,
                'sacco_id' => $saccoId,
                'status' => true,
                'type' => $type,
            ],
        );
    }
}
