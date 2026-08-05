<?php

declare(strict_types=1);

namespace Tests\Feature\Super;

use App\Enums\SaccoClaimStatus;
use App\Enums\UserType;
use App\Models\AuditLog;
use App\Models\PlatformNotification;
use App\Models\Sacco;
use App\Models\SaccoUser;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Queues\QueueTestCase;

/**
 * The directory claim-review workflow: the queue listing (with duplicate-suspect
 * hints) and the three admin actions layered on top of App\Observers\SaccoObserver.
 * approve/reject/merge each also flip a column the observer itself watches
 * (claim_status / status), so each call legitimately produces the OBSERVER's own
 * audit row (sacco.claimed / sacco.status.changed) in addition to this
 * controller's own admin-decision audit row — assertions below target rows by
 * action name, never by a raw count, per TenantEventsTest's convention.
 */
final class DirectoryTest extends QueueTestCase
{
    private function superAdmin(): User
    {
        $user = $this->makeUser();
        $user->forceFill(['type' => UserType::Superadmin])->save();
        Permission::findOrCreate('View Platform Notifications', 'web');
        $user->givePermissionTo('View Platform Notifications');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user;
    }

    #[Test]
    public function the_list_surfaces_suggested_duplicate_matches(): void
    {
        $original = Sacco::create([
            'name' => 'Nicco Sacco', 'status' => 1, 'brand' => 'testing',
            'claim_status' => SaccoClaimStatus::Directory,
        ]);
        $near = Sacco::create([
            'name' => 'Nicco Saco', 'status' => 1, 'brand' => 'testing',
            'claim_status' => SaccoClaimStatus::PendingReview,
        ]);

        Sanctum::actingAs($this->superAdmin());

        $row = $this->getJson('/api/v1/super/directory?'.http_build_query(['q' => 'Nicco Saco']))
            ->assertOk()
            ->json('data.0');

        $this->assertSame($near->id, $row['id']);
        $this->assertNotEmpty($row['suggested_matches']);
        $this->assertSame($original->id, $row['suggested_matches'][0]['id']);
    }

    #[Test]
    public function created_via_reflects_the_saccos_source(): void
    {
        Sacco::create(['name' => 'Sasra Imported', 'status' => 1, 'brand' => 'testing', 'source' => 'sasra']);
        Sacco::create(['name' => 'Driver Submitted', 'status' => 1, 'brand' => 'testing', 'source' => 'driver_submitted']);

        Sanctum::actingAs($this->superAdmin());

        $this->getJson('/api/v1/super/directory?'.http_build_query(['q' => 'Sasra Imported']))
            ->assertOk()
            ->assertJsonPath('data.0.created_via', 'directory');

        $this->getJson('/api/v1/super/directory?'.http_build_query(['q' => 'Driver Submitted']))
            ->assertOk()
            ->assertJsonPath('data.0.created_via', 'driver_onboarding');
    }

    #[Test]
    public function approve_claims_the_sacco_and_audits_the_admin_decision(): void
    {
        $sacco = Sacco::create([
            'name' => 'Pending Co', 'status' => 1, 'brand' => 'testing',
            'claim_status' => SaccoClaimStatus::PendingReview,
        ]);

        Sanctum::actingAs($this->superAdmin());

        $this->postJson("/api/v1/super/directory/{$sacco->id}/approve")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('sacco.claim_status', 'claimed');

        $sacco->refresh();
        $this->assertSame(SaccoClaimStatus::Claimed, $sacco->claim_status);
        $this->assertNotNull($sacco->verified_at);

        $adminAudit = AuditLog::where('action', 'sacco.directory.approved')
            ->where('subject_id', (string) $sacco->id)->first();
        $this->assertNotNull($adminAudit, 'The admin decision itself must be audited.');

        // The observer's own event fired naturally off the claim_status flip.
        $this->assertNotNull(PlatformNotification::where('event', 'sacco.claimed')->first());
    }

    #[Test]
    public function reject_deactivates_the_sacco_with_a_reason_and_drops_it_from_the_directory(): void
    {
        $sacco = $this->makeSacco();

        Sanctum::actingAs($this->superAdmin());

        $this->postJson("/api/v1/super/directory/{$sacco->id}/reject", ['reason' => 'Duplicate of an existing SACCO'])
            ->assertOk()
            ->assertJsonPath('success', true);

        $sacco->refresh();
        $this->assertSame(0, (int) $sacco->status);

        $audit = AuditLog::where('action', 'sacco.directory.rejected')
            ->where('subject_id', (string) $sacco->id)->first();
        $this->assertNotNull($audit);
        $this->assertSame('Duplicate of an existing SACCO', $audit->data['reason']);

        $this->assertNotNull(PlatformNotification::where('event', 'sacco.directory.rejected')->first());

        // Deactivated -> no longer inside the (status=1) directory queue.
        $ids = $this->getJson('/api/v1/super/directory')->assertOk()->json('data.*.id');
        $this->assertNotContains($sacco->id, $ids);
    }

    #[Test]
    public function reject_without_a_reason_is_rejected(): void
    {
        $sacco = $this->makeSacco();

        Sanctum::actingAs($this->superAdmin());

        $this->postJson("/api/v1/super/directory/{$sacco->id}/reject", [])
            ->assertStatus(422);
    }

    #[Test]
    public function merge_reassigns_drivers_vehicles_and_memberships_onto_the_target(): void
    {
        $losing = $this->makeSacco();
        $winning = $this->makeSacco();

        $driver = $this->makeUser([], $losing);
        $driver->forceFill(['type' => UserType::Driver])->save();
        SaccoUser::create(['user_id' => $driver->id, 'sacco_id' => $losing->id, 'start_date' => now(), 'status' => 1, 'created_by' => $driver->id]);

        $owner = $this->makeUser([], $losing);
        $vehicle = $this->makeVehicle($losing, $owner, $this->makeSeat());

        Sanctum::actingAs($this->superAdmin());

        $this->postJson("/api/v1/super/directory/{$losing->id}/merge", ['into_sacco_id' => $winning->id])
            ->assertOk()
            ->assertJsonPath('success', true);

        $driver->refresh();
        $owner->refresh();
        $vehicle->refresh();
        $this->assertSame($winning->id, $driver->sacco_id);
        $this->assertSame($winning->id, $owner->sacco_id);
        $this->assertSame($winning->id, $vehicle->sacco_id);
        $this->assertSame($winning->id, SaccoUser::where('user_id', $driver->id)->first()->sacco_id);

        $losing->refresh();
        $this->assertSame(0, (int) $losing->status, 'The losing row must deactivate, never hard-delete.');
        $this->assertNotNull(Sacco::find($losing->id), 'The losing row must survive for the audit trail.');

        $audit = AuditLog::where('action', 'sacco.directory.merged')
            ->where('subject_id', (string) $losing->id)->first();
        $this->assertNotNull($audit);
        $this->assertSame($winning->id, $audit->data['intoSaccoId']);
        $this->assertSame(2, $audit->data['reassignedDrivers']); // driver + vehicle owner
        $this->assertSame(1, $audit->data['reassignedVehicles']);

        $this->assertNotNull(PlatformNotification::where('event', 'sacco.directory.merged')->first());
    }

    #[Test]
    public function merge_conflicts_when_both_saccos_are_already_claimed(): void
    {
        $losing = Sacco::create([
            'name' => 'Losing Claimed', 'status' => 1, 'brand' => 'testing',
            'claim_status' => SaccoClaimStatus::Claimed,
        ]);
        $winning = Sacco::create([
            'name' => 'Winning Claimed', 'status' => 1, 'brand' => 'testing',
            'claim_status' => SaccoClaimStatus::Claimed,
        ]);

        Sanctum::actingAs($this->superAdmin());

        $this->postJson("/api/v1/super/directory/{$losing->id}/merge", ['into_sacco_id' => $winning->id])
            ->assertStatus(409);
    }

    #[Test]
    public function merge_requires_a_valid_target(): void
    {
        $sacco = $this->makeSacco();

        Sanctum::actingAs($this->superAdmin());

        $this->postJson("/api/v1/super/directory/{$sacco->id}/merge", ['into_sacco_id' => 999999])
            ->assertStatus(422);
    }
}
