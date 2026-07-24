<?php

namespace Database\Seeders;

use App\Auth\Roles;
use App\Enums\UserType;
use App\Models\Gender;
use App\Models\Sacco;
use App\Models\Seat;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

/**
 * Demo data for eyeballing the legacy web dashboard locally — one login per
 * account type plus a small komiut-branded fleet. Idempotent: safe to re-run.
 *
 * Login is email + password (all demo users share the password below). The
 * meaningful web UIs are Superadmin (sees every SACCO) and Admin (one SACCO,
 * SaccoScope-filtered). Driver/Passenger exist so you can log in and look, but
 * their real entry points are the mobile app (phone+plate / Google) — expect
 * their web view to be thin or bounce.
 */
class DemoSeeder extends Seeder
{
    private const PASSWORD = 'password';
    private const BRAND = 'komiut';

    public function run(): void
    {
        // Ensure the roles exist FIRST. The legacy PermissionSeeder only guards on
        // "Super Admin", and if it's missing it unconditionally re-creates "User"
        // too — which throws once "User" already exists. Pre-creating Super Admin
        // makes that null-check short-circuit, so PermissionSeeder just loads the
        // permission set. ('User' is also ensured for the same reason.)
        Role::firstOrCreate(['name' => 'User']);
        Role::firstOrCreate(['name' => Roles::SUPER_ADMIN]);

        // Permission catalog (CSV) + role bundles (Super Admin = all, SACCO Admin =
        // all minus platform-only, plus the granular roles). Names come from the
        // App\Auth\Roles constants, so no 'Sacco Admin' vs 'SACCO Admin' drift.
        $this->call(PermissionSeeder::class);
        $this->call(RoleSeeder::class);
        $superAdminRole = Role::where('name', Roles::SUPER_ADMIN)->first();
        $saccoAdminRole = Role::where('name', Roles::SACCO_ADMIN)->first();

        $gender = Gender::firstOrCreate(['name' => 'Male'], ['status' => true]);

        // Brand is stamped explicitly: the BelongsToBrand auto-stamp only fires
        // inside a request with an active brand context, never in a CLI seed, so
        // without this the rows would be brand=NULL and vanish the moment the API
        // is hit with an X-App-Key (BrandScope filters on brand).
        $sacco = Sacco::updateOrCreate(
            ['name' => 'Nairobi CBD SACCO'],
            [
                'slogan' => 'Moving the city',
                'phone' => '0700000100',
                'status' => 1,
                'brand' => self::BRAND,
            ],
        );

        $seat = Seat::firstOrCreate(
            ['name' => '14-Seater'],
            ['seats' => 14, 'rows' => 4, 'columns' => 4, 'status' => true],
        );

        // --- one user per account type (password auto-hashes via the model's
        // `hashed` cast — do NOT Hash::make here or it double-hashes) ---
        $superadmin = $this->user('superadmin@komiut.test', 'Super', 'Admin', '0711000001', $gender->id, null, UserType::Superadmin);
        $superadmin->syncRoles([$superAdminRole]);

        $saccoAdmin = $this->user('admin@komiut.test', 'Sacco', 'Admin', '0711000002', $gender->id, $sacco->id, UserType::Admin);
        $saccoAdmin->syncRoles([$saccoAdminRole]);

        $driver = $this->user('driver@komiut.test', 'Demo', 'Driver', '0711000003', $gender->id, $sacco->id, UserType::Driver);
        $this->user('passenger@komiut.test', 'Demo', 'Passenger', '0711000004', $gender->id, null, UserType::Passenger);

        // A small fleet for the SACCO so the admin's dashboard isn't empty.
        foreach ([['KDA 001A', '001'], ['KDA 002B', '002']] as [$plate, $fleet]) {
            Vehicle::updateOrCreate(
                ['plate' => $plate],
                [
                    'fleet_no' => $fleet,
                    'sacco_id' => $sacco->id,
                    'user_id' => $driver->id,
                    'seat_id' => $seat->id,
                    'status' => true,
                    'brand' => self::BRAND,
                ],
            );
        }

        // Demo Nairobi corridors (routes + ordered stops + fares) for the
        // point-first booking UX.
        $this->call(NairobiRoutesSeeder::class);
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
                'password' => self::PASSWORD,
                'gender_id' => $genderId,
                'sacco_id' => $saccoId,
                'status' => true,
                'type' => $type,
            ],
        );
    }
}
