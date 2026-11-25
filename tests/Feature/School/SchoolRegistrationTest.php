<?php

declare(strict_types=1);

namespace Tests\Feature\School;

use App\Auth\Roles;
use App\Enums\SaccoClaimStatus;
use App\Enums\UserType;
use App\Models\Sacco;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\Feature\Queues\QueueTestCase;

/**
 * A school signs itself up through the same `register/sacco` door a SACCO
 * does, with "kind":"school". It must come out as a school whose first admin
 * holds School Admin -- a bundle with no money in it -- and the SACCO path
 * beside it must not change at all.
 */
final class SchoolRegistrationTest extends QueueTestCase
{
    private const REGISTER = '/api/auth/register/sacco';

    /** Money a school must never be handed: it collects no fares. */
    private const MONEY = [
        'View Transactions', 'Add Transactions', 'View Summaries', 'View Payment Settings',
        'Add Payment Settings', 'Edit Payment Settings', 'View QRCode Payments', 'View Fares',
        'Edit Fares', 'View Loyalty', 'View Expense And Fees', 'Manage Bank Till Requests',
        Roles::EDIT_VEHICLE_BANK, 'View Passengers',
    ];

    /** @return array<string, string> */
    private function form(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Greenfield Academy',
            'email' => 'office@greenfield.ac.ke',
            'phone' => '0700111333',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
        ], $overrides);
    }

    #[Test]
    public function a_school_registers_as_a_school_with_a_school_admin(): void
    {
        $this->seed(RoleSeeder::class);

        $response = $this->postJson(self::REGISTER, $this->form(['kind' => 'school']))->assertOk();

        $school = Sacco::withoutGlobalScopes()->where('name', 'Greenfield Academy')->firstOrFail();
        $this->assertSame(Sacco::KIND_SCHOOL, $school->kind);
        $this->assertTrue($school->isSchool());
        $this->assertSame(SaccoClaimStatus::Claimed, $school->claim_status);

        $admin = User::where('email', 'office@greenfield.ac.ke')->firstOrFail();
        $this->assertSame($school->id, $admin->sacco_id);
        $this->assertSame(UserType::Admin, $admin->type);
        $this->assertTrue($admin->hasRole(Roles::SCHOOL_ADMIN));
        $this->assertFalse($admin->hasRole(Roles::SACCO_ADMIN));

        $permissions = $response->json('permissions');
        $this->assertContains('View Vehicles', $permissions);
        $this->assertContains('Edit Vehicles', $permissions);
        $this->assertContains('View Routes', $permissions);
        foreach (self::MONEY as $money) {
            $this->assertNotContains($money, $permissions, "A school admin must not hold {$money}.");
        }
    }

    #[Test]
    public function a_sacco_registers_exactly_as_before(): void
    {
        $this->seed(RoleSeeder::class);

        $this->postJson(self::REGISTER, $this->form(['name' => 'Umoja SACCO', 'email' => 'admin@umoja.co.ke']))->assertOk();

        $sacco = Sacco::withoutGlobalScopes()->where('name', 'Umoja SACCO')->firstOrFail();
        $this->assertSame(Sacco::KIND_SACCO, $sacco->kind);

        $admin = User::where('email', 'admin@umoja.co.ke')->firstOrFail();
        $this->assertTrue($admin->hasRole(Roles::SACCO_ADMIN));
        $this->assertFalse($admin->hasRole(Roles::SCHOOL_ADMIN));

        // "sacco" said out loud is the same thing.
        $this->postJson(self::REGISTER, $this->form([
            'name' => 'Kenya Mpya', 'email' => 'ops@kenyampya.co.ke', 'phone' => '0711222333', 'kind' => 'sacco',
        ]))->assertOk();
        $this->assertSame(Sacco::KIND_SACCO, Sacco::withoutGlobalScopes()->where('name', 'Kenya Mpya')->value('kind'));
    }

    #[Test]
    public function an_unknown_kind_is_refused_before_anything_is_created(): void
    {
        $this->postJson(self::REGISTER, $this->form(['kind' => 'bank']))
            ->assertStatus(400)
            ->assertJsonPath('errors.kind.0', 'Choose sacco or school.');

        $this->assertNull(User::where('email', 'office@greenfield.ac.ke')->first());
        $this->assertNull(Sacco::withoutGlobalScopes()->where('name', 'Greenfield Academy')->first());
    }

    #[Test]
    public function a_school_never_claims_a_sacco_directory_entry(): void
    {
        // An unclaimed stub with nothing attached: a SACCO registering this
        // name would claim it. A school must not turn it into a school.
        $stub = Sacco::create([
            'name' => 'Greenfield Academy',
            'status' => 1,
            'brand' => 'testing',
            'claim_status' => SaccoClaimStatus::Directory,
        ]);

        $this->postJson(self::REGISTER, $this->form(['kind' => 'school']))
            ->assertStatus(400)
            ->assertJsonStructure(['errors' => ['name']]);

        $stub->refresh();
        $this->assertSame(Sacco::KIND_SACCO, $stub->kind);
        $this->assertNotSame(SaccoClaimStatus::Claimed, $stub->claim_status);
        $this->assertNull(User::where('email', 'office@greenfield.ac.ke')->first());
    }

    #[Test]
    public function the_first_school_on_an_unseeded_server_still_gets_a_working_admin(): void
    {
        // Production deploys migrate and never seed: the role may not exist
        // until the first school registers. It must arrive with its bundle,
        // not as an empty role that 403s every screen.
        $this->assertNull(Role::where('name', Roles::SCHOOL_ADMIN)->first());

        $response = $this->postJson(self::REGISTER, $this->form(['kind' => 'school']))->assertOk();

        $role = Role::findByName(Roles::SCHOOL_ADMIN, 'web');
        $this->assertEqualsCanonicalizing(
            Roles::bundles()[Roles::SCHOOL_ADMIN],
            $role->permissions->pluck('name')->all(),
        );
        $this->assertContains('View Vehicles', $response->json('permissions'));
    }

    #[Test]
    public function the_school_admin_bundle_is_inside_the_sacco_admin_ceiling_and_assignable(): void
    {
        $bundle = Roles::bundles()[Roles::SCHOOL_ADMIN];

        $this->assertSame([], array_values(array_intersect($bundle, Roles::PLATFORM_ONLY)));
        $this->assertSame([], array_values(array_intersect($bundle, self::MONEY)));
        // A School Admin can hand out Driver: everything Driver carries is in
        // the School Admin bundle (the permission ceiling).
        $this->assertSame([], array_values(array_diff(Roles::bundles()[Roles::DRIVER], $bundle)));
        $this->assertContains(Roles::SCHOOL_ADMIN, Roles::saccoAssignable());
        $this->assertContains(Roles::SCHOOL_ADMIN, Roles::OFFICE_STAFF);
        $this->assertNotContains(Roles::SCHOOL_ADMIN, Roles::FULL_FLEET_VIEW);
    }
}
