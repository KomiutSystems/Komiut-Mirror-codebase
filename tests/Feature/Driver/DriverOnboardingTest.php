<?php

declare(strict_types=1);

namespace Tests\Feature\Driver;

use App\Auth\Roles;
use App\Enums\BankPartner;
use App\Enums\SaccoClaimStatus;
use App\Enums\UserType;
use App\Models\DriverBankLead;
use App\Models\Sacco;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleUser;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Queues\QueueTestCase;

/**
 * Street onboarding: a marketing agent signs a driver up at the stage, before
 * either the driver or their SACCO has an account.
 */
final class DriverOnboardingTest extends QueueTestCase
{
    private const ENDPOINT = '/api/v1/auth/driver/onboard';

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'firstname' => 'Peter',
            'lastname' => 'Kamau',
            'phone' => '0722000111',
            'id_number' => '24567890',
            'plate' => 'KDQ446R',
            // Required for every sign-up now that the opt-in question is gone
            // from the form and every driver is treated as an NCBA lead.
            'preferred_branch' => 'Thika Road',
        ], $overrides);
    }

    #[Test]
    public function onboarding_into_a_known_sacco_creates_driver_vehicle_and_assignment(): void
    {
        $sacco = $this->makeSacco();

        $response = $this->postJson(self::ENDPOINT, $this->payload(['sacco_id' => $sacco->id]));

        $response->assertCreated()
            ->assertJsonPath('driver.type', 'driver')
            ->assertJsonPath('sacco.id', $sacco->id)
            ->assertJsonPath('vehicle.plate', 'KDQ446R')
            ->assertJsonStructure(['driver' => ['id'], 'sacco', 'vehicle', 'next_step']);

        $driver = User::where('phone', '0722000111')->firstOrFail();
        $this->assertSame(UserType::Driver, $driver->type);
        $this->assertSame($sacco->id, $driver->sacco_id);
        $this->assertSame('24567890', $driver->id_number);
        $this->assertTrue((bool) $driver->status);
        // Drivers never use a password, but the column is a real one — it must
        // hold something unguessable rather than being left empty.
        $this->assertNotEmpty($driver->getAttributes()['password']);

        $vehicle = Vehicle::where('plate', 'KDQ446R')->firstOrFail();
        $this->assertSame($sacco->id, $vehicle->sacco_id);

        $this->assertDatabaseHas('vehicle_users', [
            'user_id' => $driver->id,
            'vehicle_id' => $vehicle->id,
            'sacco_id' => $sacco->id,
            'status' => true,
            'end_date' => null,
        ]);
    }

    #[Test]
    public function a_sacco_we_have_never_heard_of_is_submitted_to_the_directory(): void
    {
        $response = $this->postJson(self::ENDPOINT, $this->payload(['sacco_name' => 'Nicco SACCO']));

        $response->assertCreated();

        $sacco = Sacco::where('name', 'Nicco SACCO')->firstOrFail();
        $this->assertSame(SaccoClaimStatus::PendingReview, $sacco->claim_status);
        $this->assertSame('testing', $sacco->brand);
        $this->assertSame($sacco->id, $response->json('sacco.id'));
    }

    #[Test]
    public function onboarding_the_same_phone_twice_moves_the_driver_instead_of_duplicating_them(): void
    {
        $sacco = $this->makeSacco();

        $this->postJson(self::ENDPOINT, $this->payload(['sacco_id' => $sacco->id]))->assertCreated();
        $this->postJson(self::ENDPOINT, $this->payload([
            'sacco_id' => $sacco->id,
            'plate' => 'KDB123Z',
        ]))->assertCreated();

        $this->assertSame(1, User::where('phone', '0722000111')->count());

        $driver = User::where('phone', '0722000111')->firstOrFail();
        $old = Vehicle::where('plate', 'KDQ446R')->firstOrFail();
        $new = Vehicle::where('plate', 'KDB123Z')->firstOrFail();

        $assignments = VehicleUser::where('user_id', $driver->id)->get();
        $this->assertCount(2, $assignments, 'The rotation history must be kept, not overwritten.');

        $closed = $assignments->firstWhere('vehicle_id', $old->id);
        $this->assertNotNull($closed->end_date);
        $this->assertFalse((bool) $closed->status);

        $open = $assignments->firstWhere('vehicle_id', $new->id);
        $this->assertNull($open->end_date);
        $this->assertTrue((bool) $open->status);
    }

    #[Test]
    public function a_plate_typed_with_spaces_resolves_to_the_same_vehicle(): void
    {
        $sacco = $this->makeSacco();

        $this->postJson(self::ENDPOINT, $this->payload(['sacco_id' => $sacco->id]))->assertCreated();
        $this->postJson(self::ENDPOINT, $this->payload([
            'sacco_id' => $sacco->id,
            'plate' => 'kdq 446r',
        ]))->assertCreated();

        $this->assertSame(1, Vehicle::count(), 'Casing and spacing must not fork the vehicle.');
    }

    #[Test]
    public function opting_in_creates_a_bank_lead_whose_bank_comes_from_the_brand(): void
    {
        $sacco = $this->makeSacco();

        $this->postJson(self::ENDPOINT, $this->payload([
            'sacco_id' => $sacco->id,
            'vehicle_capacity' => 14,
            'bank_opt_in' => true,
            'preferred_branch' => 'Thika Road',
            // Ignored on purpose: the client does not get to pick the bank.
            'bank' => 'coop',
        ]))->assertCreated();

        $driver = User::where('phone', '0722000111')->firstOrFail();
        $lead = DriverBankLead::where('user_id', $driver->id)->firstOrFail();

        $this->assertSame(BankPartner::forBrand('testing'), $lead->bank);
        $this->assertSame(BankPartner::Ncba, $lead->bank);
        $this->assertSame('Thika Road', $lead->preferred_branch);
        $this->assertSame(14, $lead->vehicle_capacity);
        $this->assertSame('new', $lead->status);
        $this->assertSame('testing', $lead->brand);
        $this->assertNotNull($lead->opted_in_at);
    }

    #[Test]
    public function the_partner_bank_is_fixed_per_brand(): void
    {
        $this->assertSame(BankPartner::Ncba, BankPartner::forBrand('komiut'));
        $this->assertSame(BankPartner::Coop, BankPartner::forBrand('2safiri'));
        $this->assertSame(BankPartner::Coop, BankPartner::forBrand('safiri'));
    }

    #[Test]
    public function no_lead_is_recorded_when_the_driver_does_not_opt_in(): void
    {
        $sacco = $this->makeSacco();

        $this->postJson(self::ENDPOINT, $this->payload([
            'sacco_id' => $sacco->id,
            'bank_opt_in' => false,
        ]))->assertCreated();

        $this->assertSame(0, DriverBankLead::count());
    }

    #[Test]
    public function a_sign_up_without_a_branch_is_rejected(): void
    {
        $sacco = $this->makeSacco();

        $this->postJson(self::ENDPOINT, $this->payload([
            'sacco_id' => $sacco->id,
            'preferred_branch' => null,
        ]))->assertStatus(400)->assertJsonStructure(['errors' => ['preferred_branch']]);
    }

    #[Test]
    public function a_sacco_must_be_named_one_way_or_the_other(): void
    {
        $this->postJson(self::ENDPOINT, $this->payload())
            ->assertStatus(400)
            ->assertJsonStructure(['errors' => ['sacco_id', 'sacco_name']]);
    }

    #[Test]
    public function a_phone_that_is_not_ten_digits_is_rejected(): void
    {
        $sacco = $this->makeSacco();

        $this->postJson(self::ENDPOINT, $this->payload([
            'sacco_id' => $sacco->id,
            'phone' => '722000111',
        ]))->assertStatus(400)->assertJsonStructure(['errors' => ['phone']]);
    }

    #[Test]
    public function an_onboarded_driver_receives_the_seeded_driver_role(): void
    {
        $sacco = $this->makeSacco();
        // Seeded under the `web` guard in production; RoleSeeder does not run here.
        Permission::findOrCreate('Edit Queues', 'web');
        Role::findOrCreate(Roles::DRIVER, 'web')->givePermissionTo('Edit Queues');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->postJson(self::ENDPOINT, $this->payload(['sacco_id' => $sacco->id]))->assertCreated();

        $driver = User::where('phone', '0722000111')->firstOrFail();
        $this->assertTrue($driver->hasRole(Roles::DRIVER));
        // The gate on every driver endpoint (queues/join, trips/start, …).
        $this->assertTrue($driver->can('Edit Queues'));
    }

    #[Test]
    public function onboarding_still_succeeds_when_the_driver_role_is_not_seeded(): void
    {
        $sacco = $this->makeSacco();

        $this->postJson(self::ENDPOINT, $this->payload(['sacco_id' => $sacco->id]))->assertCreated();

        $this->assertSame(0, Role::count(), 'A missing role must not be conjured with no permissions.');
    }

    #[Test]
    public function the_users_email_index_survives_being_made_nullable(): void
    {
        // The onboarding migration relaxes users.email, which carries a unique
        // constraint the validator's `unique:` rules do not stand in for.
        $indexes = collect(Schema::getIndexes('users'))
            ->filter(fn (array $index): bool => $index['columns'] === ['email']);

        $this->assertTrue(
            $indexes->contains(fn (array $index): bool => $index['unique'] === true),
            'users.email must still be uniquely indexed.'
        );
    }

    #[Test]
    public function an_onboarded_driver_can_sign_in_with_their_phone_and_plate(): void
    {
        $sacco = $this->makeSacco();

        $this->postJson(self::ENDPOINT, $this->payload(['sacco_id' => $sacco->id]))->assertCreated();

        $this->postJson('/api/v1/auth/driver/login', [
            'phone' => '0722000111',
            'plate' => 'KDQ446R',
        ])->assertOk()->assertJsonStructure(['user', 'vehicle', 'access_token', 'token_type', 'expires_at']);
    }

    #[Test]
    public function a_duplicate_email_is_reported_not_crashed(): void
    {
        // On 10 Aug two sign-ups for the same driver returned a bare 500:
        // `unique` passed validation, the INSERT hit users_email_unique, and the
        // agent standing beside the driver got nothing to act on and retried.
        $sacco = $this->makeSacco();
        $this->postJson(self::ENDPOINT, $this->payload([
            'sacco_id' => $sacco->id,
            'email' => 'taken@example.test',
        ]))->assertCreated();

        $this->postJson(self::ENDPOINT, $this->payload([
            'sacco_id' => $sacco->id,
            'email' => 'taken@example.test',
            'phone' => '0722000222',
            'plate' => 'KDQ999Z',
        ]))
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['email']]);
    }

    #[Test]
    public function every_sign_up_becomes_a_bank_lead_without_being_asked(): void
    {
        // The opt-in checkbox is gone: onboarding is an NCBA drive, so the lead
        // is created from the branch alone. Three of the first five sign-ups
        // never reached the bank because the box was left unticked.
        $sacco = $this->makeSacco();

        $this->postJson(self::ENDPOINT, $this->payload([
            'sacco_id' => $sacco->id,
            'preferred_branch' => 'Kiambu',
        ]))->assertCreated();

        $this->assertSame(1, DriverBankLead::count());
        $this->assertSame('Kiambu', DriverBankLead::first()->preferred_branch);
    }

    #[Test]
    public function the_account_number_and_consent_are_recorded_on_the_lead(): void
    {
        // NCBA lets a driver open an account from their own phone, so by the
        // time the agent is standing with them they often already have one --
        // and a number the bank can act on beats a name it has to chase.
        $sacco = $this->makeSacco();

        $this->postJson(self::ENDPOINT, $this->payload([
            'sacco_id' => $sacco->id,
            'account_number' => '1234567890',
            'bank_consent' => true,
            'consent_text_version' => '2026-08-a',
            'agent_identifier' => 'nate.m',
        ]))->assertCreated();

        $lead = $this->leadForTestDriver();

        $this->assertSame('1234567890', $lead->account_number);
        $this->assertNotNull($lead->consent_given_at);
        // A boolean alone would not survive the disclosure wording changing, and
        // an attestation naming nobody is worth very little.
        $this->assertSame('2026-08-a', $lead->consent_text_version);
        $this->assertSame('nate.m', $lead->consent_agent);
        $this->assertNotNull($lead->consent_ip);
    }

    #[Test]
    public function consent_is_absent_until_the_box_is_actually_ticked(): void
    {
        $sacco = $this->makeSacco();

        $this->postJson(self::ENDPOINT, $this->payload(['sacco_id' => $sacco->id]))->assertCreated();

        $this->assertNull($this->leadForTestDriver()->consent_given_at);
    }

    #[Test]
    public function re_onboarding_never_blanks_what_the_bank_is_working_from(): void
    {
        // An agent moving the driver to another matatu -- or an older client
        // that does not send these fields -- must not wipe an account number the
        // bank already has, nor void an attestation that was properly taken.
        $sacco = $this->makeSacco();

        $this->postJson(self::ENDPOINT, $this->payload([
            'sacco_id' => $sacco->id,
            'account_number' => '1234567890',
            'bank_consent' => true,
            'consent_text_version' => '2026-08-a',
        ]))->assertCreated();

        $this->postJson(self::ENDPOINT, $this->payload([
            'sacco_id' => $sacco->id,
            'plate' => 'KDB777Z',
        ]))->assertCreated();

        $lead = $this->leadForTestDriver();

        $this->assertSame('1234567890', $lead->account_number);
        $this->assertNotNull($lead->consent_given_at);
        $this->assertSame('2026-08-a', $lead->consent_text_version);
    }

    /**
     * driver/onboard is PUBLIC and matches on a phone number, which is not a
     * secret. Its one write to an EXISTING account was therefore reachable by
     * anyone: post a phone with any SACCO name and that account moved to that
     * SACCO, came back on if it had been switched off, and became a Driver.
     *
     * The four tests below are that hole and the one case that must survive it.
     */
    #[Test]
    public function a_driver_at_another_sacco_cannot_be_pulled_across_by_phone_number(): void
    {
        $theirs = $this->makeSacco();
        $mine = $this->makeSacco();

        $this->postJson(self::ENDPOINT, $this->payload(['sacco_id' => $theirs->id]))->assertCreated();

        $this->postJson(self::ENDPOINT, $this->payload([
            'sacco_id' => $mine->id,
            'plate' => 'KDB123Z',
        ]))->assertStatus(409);

        $driver = User::where('phone', '0722000111')->firstOrFail();
        $this->assertSame(
            $theirs->id,
            $driver->sacco_id,
            'a driver must not change SACCO through an unauthenticated endpoint'
        );
    }

    #[Test]
    public function a_deactivated_account_is_not_switched_back_on_by_re_onboarding(): void
    {
        // status was set to true unconditionally, so suspending a driver was
        // pointless — anyone who knew the number could undo it.
        $sacco = $this->makeSacco();

        $this->postJson(self::ENDPOINT, $this->payload(['sacco_id' => $sacco->id]))->assertCreated();

        $driver = User::where('phone', '0722000111')->firstOrFail();
        $driver->forceFill(['status' => false])->save();

        $this->postJson(self::ENDPOINT, $this->payload([
            'sacco_id' => $sacco->id,
            'plate' => 'KDB123Z',
        ]))->assertStatus(409);

        $this->assertFalse((bool) $driver->fresh()->status);
    }

    #[Test]
    public function a_staff_account_is_never_rewritten_by_street_onboarding(): void
    {
        // The worst version: a SACCO admin quietly reassigned to someone else's
        // SACCO, taking their dashboard with them.
        $theirs = $this->makeSacco();
        $mine = $this->makeSacco();

        $admin = $this->makeUser([], $theirs);
        $admin->forceFill(['type' => UserType::Admin, 'phone' => '0722000111'])->save();

        $this->postJson(self::ENDPOINT, $this->payload(['sacco_id' => $mine->id]))
            ->assertStatus(409);

        $admin = $admin->fresh();
        $this->assertSame($theirs->id, $admin->sacco_id);
        $this->assertSame(UserType::Admin, $admin->type);
    }

    #[Test]
    public function a_passenger_with_no_sacco_is_still_adopted_as_a_driver(): void
    {
        // The case the reuse-by-phone behaviour exists for, and the reason this
        // is a targeted gate rather than "never touch an existing account": a
        // passenger who starts driving keeps one account and one history.
        $sacco = $this->makeSacco();

        $passenger = $this->makeUser([], null);
        $passenger->forceFill(['type' => UserType::Passenger, 'phone' => '0722000111'])->save();

        $this->postJson(self::ENDPOINT, $this->payload(['sacco_id' => $sacco->id]))
            ->assertCreated();

        $adopted = $passenger->fresh();
        $this->assertSame($sacco->id, $adopted->sacco_id);
        $this->assertSame(UserType::Driver, $adopted->type);
        $this->assertSame(1, User::where('phone', '0722000111')->count(), 'no second account');
    }

    private function leadForTestDriver(): DriverBankLead
    {
        return DriverBankLead::withoutGlobalScopes()
            ->whereHas('user', fn ($q) => $q->where('phone', '0722000111'))
            ->firstOrFail();
    }
}
