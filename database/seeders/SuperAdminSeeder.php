<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Auth\Roles;
use App\Enums\UserType;
use App\Models\Gender;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Seeds one platform Super Admin account, idempotently, from env — a no-op
 * unless SUPERADMIN_EMAIL/SUPERADMIN_PASSWORD/SUPERADMIN_PHONE are all set, so
 * it is safe to run on every deploy and never hardcodes a real credential in
 * source. Must run after RoleSeeder (needs the 'Super Admin' role to exist).
 */
class SuperAdminSeeder extends Seeder
{
    public function run(): void
    {
        $email = env('SUPERADMIN_EMAIL');
        $password = env('SUPERADMIN_PASSWORD');
        $phone = env('SUPERADMIN_PHONE');

        if (! $email || ! $password || ! $phone) {
            return;
        }

        $gender = Gender::firstOrCreate(['name' => 'Male'], ['status' => true]);

        $user = User::firstOrCreate(
            ['email' => $email],
            [
                'firstname' => 'Super',
                'lastname' => 'Admin',
                'phone' => $phone,
                'password' => $password,
                'gender_id' => $gender->id,
                'type' => UserType::Superadmin,
                'status' => true,
            ]
        );

        if (! $user->hasRole(Roles::SUPER_ADMIN)) {
            $user->assignRole(Roles::SUPER_ADMIN);
        }
    }
}
