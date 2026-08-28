<?php

declare(strict_types=1);

namespace Tests\Feature\Super;

use App\Auth\Roles;
use App\Enums\Financier;
use App\Enums\UserType;
use App\Models\AuditLog;
use App\Models\PlatformNotification;
use App\Models\Sacco;
use App\Models\User;
use Illuminate\Support\Facades\Context;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Queues\QueueTestCase;

/**
 * Provisioning a bank viewer — the write path for `users.financier`.
 *
 * FinancierScopeTest already proves the boundary works once the column holds a
 * value. This file is about how the value gets there, and specifically about
 * the shape it must be written in: a bank viewer with a sacco_id is scoped by
 * SaccoScope AND FinancierScope at once, which is lossless for Co-op purely by
 * accident (all 54 Co-op vehicles sit inside NICCO MOVERS) and silently costs
 * an NCBA rep 703 of their 829 vehicles. The account that motivates all of it,
 * vriungu@co-opbank.co.ke (id 6272), has sacco_id = 4 today.
 *
 * The fixture below therefore always starts the target IN a SACCO, because
 * that is the state the real provisioning has to correct.
 */
final class BankProvisioningTest extends QueueTestCase
{
    private Sacco $nicco;

    protected function setUp(): void
    {
        parent::setUp();

        Context::add('brand', 'testing');

        $this->nicco = $this->makeSacco();

        // RoleSeeder does not run in this suite, and the endpoint deliberately
        // refuses to create the role itself (an empty Bank Viewer would be an
        // account that passes every check and can read nothing).
        Role::findOrCreate(Roles::BANK_VIEWER, 'web');
    }

    /** A platform account that may reach /super. */
    private function superAdmin(): User
    {
        $user = $this->makeUser();
        $user->forceFill(['type' => UserType::Superadmin])->save();
        Permission::findOrCreate('View Platform Notifications', 'web');
        $user->givePermissionTo('View Platform Notifications');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user;
    }

    private function url(User $target): string
    {
        return "/api/v1/super/users/{$target->id}/bank-access";
    }

    #[Test]
    public function provisioning_sets_the_financier_and_clears_the_sacco(): void
    {
        $target = $this->makeUser([], $this->nicco);
        $this->assertSame($this->nicco->id, $target->sacco_id, 'Fixture must start inside a SACCO.');

        Sanctum::actingAs($this->superAdmin());

        $this->postJson($this->url($target), ['financier' => Financier::Ncba->value])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('user.financier', Financier::Ncba->value)
            ->assertJsonPath('user.sacco_id', null);

        $target->refresh();

        $this->assertSame(Financier::Ncba->value, $target->financier);
        $this->assertNull($target->sacco_id, 'A bank viewer keeping a sacco_id is the silent-loss case.');
        $this->assertTrue($target->hasRole(Roles::BANK_VIEWER));
        $this->assertSame(Financier::Ncba, $target->currentFinancier());
        $this->assertTrue($target->isBankUser());
    }

    #[Test]
    public function provisioning_replaces_the_accounts_other_access(): void
    {
        $target = $this->makeUser(['View Passengers'], $this->nicco);
        Role::findOrCreate(Roles::SACCO_ADMIN, 'web');
        $target->assignRole(Roles::SACCO_ADMIN);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Sanctum::actingAs($this->superAdmin());

        $this->postJson($this->url($target), ['financier' => Financier::Coop->value])->assertOk();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $target = User::find($target->id);

        // Not tidiness. Clearing sacco_id makes SaccoScope return early, so any
        // role or direct permission left on the account reads across every
        // SACCO in the brand for the models FinancierScope does not cover.
        $this->assertSame(
            [Roles::BANK_VIEWER],
            $target->getRoleNames()->all(),
            'A saccoless account keeping SACCO Admin reads every SACCO for anything unscoped by financier.',
        );
        $this->assertSame(
            [],
            $target->getDirectPermissions()->pluck('name')->all(),
            'Direct permissions bypass roles, so they must be cleared too.',
        );
    }

    #[Test]
    public function provisioning_while_keeping_a_sacco_id_is_refused(): void
    {
        $target = $this->makeUser([], $this->nicco);

        Sanctum::actingAs($this->superAdmin());

        $this->postJson($this->url($target), [
            'financier' => Financier::Ncba->value,
            'sacco_id' => $this->nicco->id,
        ])->assertStatus(422);

        $target->refresh();

        // Refused means nothing moved — not "refused but applied anyway".
        $this->assertNull($target->financier);
        $this->assertSame($this->nicco->id, $target->sacco_id);
        $this->assertFalse($target->hasRole(Roles::BANK_VIEWER));
    }

    #[Test]
    public function the_keep_sacco_flag_is_refused_the_same_way(): void
    {
        $target = $this->makeUser([], $this->nicco);

        Sanctum::actingAs($this->superAdmin());

        $this->postJson($this->url($target), [
            'financier' => Financier::Ncba->value,
            'keep_sacco' => true,
        ])->assertStatus(422);

        $this->assertNull($target->refresh()->financier);
    }

    #[Test]
    public function an_explicit_null_sacco_id_is_accepted(): void
    {
        // It agrees with what the endpoint does, so it is not the refusal case.
        $target = $this->makeUser([], $this->nicco);

        Sanctum::actingAs($this->superAdmin());

        $this->postJson($this->url($target), [
            'financier' => Financier::Ncba->value,
            'sacco_id' => null,
        ])->assertOk();

        $this->assertSame(Financier::Ncba->value, $target->refresh()->financier);
    }

    #[Test]
    public function an_unknown_financier_value_is_refused(): void
    {
        $target = $this->makeUser([], $this->nicco);

        Sanctum::actingAs($this->superAdmin());

        // Lowercase 'ncba' is the realistic typo and the dangerous one: it is
        // not a Financier case, so Financier::tryParse returns null and the
        // account fails closed onto an empty dashboard with nothing to explain
        // why. The allow-list is what turns that into a 422 at write time.
        foreach (['ncba', 'NCBA Bank', 'KCB', ''] as $bad) {
            $this->postJson($this->url($target), ['financier' => $bad])
                ->assertStatus(422)
                ->assertJsonValidationErrors('financier');
        }

        $target->refresh();
        $this->assertNull($target->financier);
        $this->assertSame($this->nicco->id, $target->sacco_id);
    }

    #[Test]
    public function a_non_superadmin_cannot_provision(): void
    {
        $target = $this->makeUser([], $this->nicco);

        // A SACCO admin with every SACCO-tier permission is still not a
        // superadmin, and /super is gated on that and nothing else.
        $caller = $this->makeUser(['View Platform Notifications'], $this->nicco);
        Role::findOrCreate(Roles::SACCO_ADMIN, 'web');
        $caller->assignRole(Roles::SACCO_ADMIN);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Sanctum::actingAs($caller);

        $this->postJson($this->url($target), ['financier' => Financier::Ncba->value])
            ->assertStatus(403);

        $this->assertNull($target->refresh()->financier);
    }

    #[Test]
    public function an_unauthenticated_caller_cannot_provision(): void
    {
        $target = $this->makeUser([], $this->nicco);

        $this->postJson($this->url($target), ['financier' => Financier::Ncba->value])
            ->assertStatus(401);

        $this->assertNull($target->refresh()->financier);
    }

    #[Test]
    public function a_super_admin_cannot_be_made_a_bank_viewer(): void
    {
        // FinancierScope exempts super admins, so the column would label the
        // account as one bank's while it still read all 883 vehicles.
        $target = $this->makeUser();
        $target->forceFill(['type' => UserType::Superadmin])->save();

        Sanctum::actingAs($this->superAdmin());

        $this->postJson($this->url($target), ['financier' => Financier::Ncba->value])
            ->assertStatus(422);

        $this->assertNull($target->refresh()->financier);
    }

    #[Test]
    public function the_financier_change_is_audited_and_alerted(): void
    {
        $target = $this->makeUser([], $this->nicco);
        $actor = $this->superAdmin();

        Sanctum::actingAs($actor);

        $this->postJson($this->url($target), ['financier' => Financier::Ncba->value])->assertOk();

        $notification = PlatformNotification::where('event', 'access.financier.changed')->first();
        $this->assertNotNull($notification, 'Which bank an account reads for must not change untraced.');
        $this->assertSame($target->id, $notification->data['targetUserId']);
        $this->assertNull($notification->data['financierBefore']);
        $this->assertSame(Financier::Ncba->value, $notification->data['financierAfter']);
        // The cleared SACCO is the half a reviewer most needs to see.
        $this->assertSame($this->nicco->id, $notification->data['saccoIdBefore']);
        $this->assertNull($notification->data['saccoIdAfter']);
        $this->assertSame($actor->id, $notification->data['changedBy']);
        $this->assertNotNull($notification->audit_id, 'The alert must reference its audit row.');

        $this->assertSame(1, AuditLog::where('action', 'access.financier.changed')->count());
    }

    #[Test]
    public function re_provisioning_the_same_bank_does_not_emit_again(): void
    {
        $target = $this->makeUser([], $this->nicco);

        Sanctum::actingAs($this->superAdmin());

        $this->postJson($this->url($target), ['financier' => Financier::Ncba->value])->assertOk();
        $this->postJson($this->url($target), ['financier' => Financier::Ncba->value])->assertOk();

        // Nothing moved the second time. An alert per no-op teaches the
        // reviewer to scroll past the one that matters.
        $this->assertSame(1, AuditLog::where('action', 'access.financier.changed')->count());
    }

    #[Test]
    public function a_sacco_admin_cannot_assign_the_bank_viewer_role(): void
    {
        // The other half of the boundary: even with the platform endpoint
        // locked to superadmins, a SACCO admin must not be able to mint a bank
        // account through the ordinary member-creation path. Bank Viewer reads
        // a whole financier's fleet across SACCOs — Co-op's 54 vehicles sit
        // inside NICCO but NCBA's 829 do not — so assigning it would be an exit
        // from the tenant boundary every other assignable role stays inside.
        $admin = $this->makeUser(['Add Sacco Members'], $this->nicco);

        Sanctum::actingAs($admin);

        $this->postJson('/api/v1/auth/saccos/members/create', [
            'firstname' => 'Bank',
            'lastname' => 'Staff',
            'email' => 'bankstaff@example.test',
            'phone' => '254799000111',
            'password' => 'password123',
            'type' => 'admin',
            'roles' => [Roles::BANK_VIEWER],
        ])->assertStatus(403);

        $this->assertNull(User::where('email', 'bankstaff@example.test')->first());
    }

    #[Test]
    public function bank_viewer_is_not_sacco_assignable(): void
    {
        // Pins the omission itself. The 403 above is a consequence of this list;
        // if Bank Viewer is ever added back, that test would start failing for a
        // reason nobody would connect to this line.
        $this->assertNotContains(
            Roles::BANK_VIEWER,
            Roles::saccoAssignable(),
            'Bank Viewer must stay superadmin-only — it crosses SACCO boundaries by design.',
        );
    }
}
