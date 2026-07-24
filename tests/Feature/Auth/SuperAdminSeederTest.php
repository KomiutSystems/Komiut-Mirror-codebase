<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Auth\Roles;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SuperAdminSeeder;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Queues\QueueTestCase;

/**
 * Database\Seeders\SuperAdminSeeder is a no-op unless env vars are set (never
 * hardcode a real credential in source) — verifies both branches.
 */
final class SuperAdminSeederTest extends QueueTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    #[Test]
    public function it_does_nothing_without_the_env_vars(): void
    {
        $this->seed(SuperAdminSeeder::class);

        $this->assertSame(0, User::count());
    }

    #[Test]
    public function it_creates_and_roles_a_superadmin_when_configured(): void
    {
        putenv('SUPERADMIN_EMAIL=super@komiut.test');
        putenv('SUPERADMIN_PASSWORD=a-strong-password');
        putenv('SUPERADMIN_PHONE=254700999888');

        try {
            $this->seed(SuperAdminSeeder::class);

            $user = User::where('email', 'super@komiut.test')->firstOrFail();
            $this->assertTrue($user->hasRole(Roles::SUPER_ADMIN));

            // Idempotent: running it again does not duplicate the account.
            $this->seed(SuperAdminSeeder::class);
            $this->assertSame(1, User::where('email', 'super@komiut.test')->count());
        } finally {
            putenv('SUPERADMIN_EMAIL');
            putenv('SUPERADMIN_PASSWORD');
            putenv('SUPERADMIN_PHONE');
        }
    }
}
