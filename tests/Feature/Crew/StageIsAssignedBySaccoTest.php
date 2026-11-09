<?php

declare(strict_types=1);

namespace Tests\Feature\Crew;

use App\Auth\Roles;
use App\Models\AuditLog;
use App\Models\SaccoTerminus;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Queues\QueueTestCase;

/**
 * Who sets a stage manager's stage: the manager, or their SACCO admin.
 *
 * Both write the same column. The manager picks in their own app from the
 * stages their SACCO works (PUT mobile/stage); the office can post or move
 * anyone from the crew screen (POST crew/{id}/stage), which is what covers a
 * manager who has not opened the app.
 *
 * The guard is the LIST, not the caller: both paths resolve terminus_id
 * through SaccoStages, so neither can land on another SACCO's stage and read
 * takings that are not theirs. Both are audited, because the stage decides
 * whose money an account can see.
 */
final class StageIsAssignedBySaccoTest extends QueueTestCase
{
    private function role(User $user, string $role): User
    {
        Role::findOrCreate($role, 'web');
        $user->assignRole($role);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }

    #[Test]
    public function the_sacco_admin_posts_a_manager_to_a_stage(): void
    {
        $world = $this->makeWorld();
        $manager = $this->role($this->makeUser([], $world['sacco']), Roles::QUEUE_SUPERVISOR);

        Sanctum::actingAs($this->makeUser(['Edit Sacco Members'], $world['sacco']));

        $this->postJson('/api/v1/auth/crew/'.$manager->id.'/stage', [
            'terminus_id' => $world['terminus']->id,
        ])->assertOk()->assertJsonPath('stage.id', (int) $world['terminus']->id);

        $this->assertSame((int) $world['terminus']->id, (int) $manager->fresh()->stage_terminus_id);
    }

    #[Test]
    public function the_posting_is_on_the_audit_log(): void
    {
        $world = $this->makeWorld();
        $manager = $this->role($this->makeUser([], $world['sacco']), Roles::QUEUE_SUPERVISOR);

        Sanctum::actingAs($this->makeUser(['Edit Sacco Members'], $world['sacco']));

        $this->postJson('/api/v1/auth/crew/'.$manager->id.'/stage', [
            'terminus_id' => $world['terminus']->id,
        ])->assertOk();

        // Who moved whom, and from where -- the only record that it happened.
        $row = AuditLog::where('action', 'sacco.stage_manager.posted')->latest('id')->first();
        $this->assertNotNull($row);
        $this->assertSame((int) $manager->id, (int) $row->data['user_id']);
        $this->assertNull($row->data['from_terminus_id']);
        $this->assertSame((int) $world['terminus']->id, (int) $row->data['to_terminus_id']);
    }

    #[Test]
    public function a_manager_picks_their_own_stage(): void
    {
        $world = $this->makeWorld();
        $manager = $this->role($this->makeUser([], $world['sacco']), Roles::QUEUE_SUPERVISOR);

        Sanctum::actingAs($manager);

        $this->putJson('/api/v1/auth/mobile/stage', ['terminus_id' => $world['terminus']->id])
            ->assertOk()
            ->assertJsonPath('stage.id', (int) $world['terminus']->id);

        $this->assertSame((int) $world['terminus']->id, (int) $manager->fresh()->stage_terminus_id);
    }

    #[Test]
    public function the_managers_own_pick_is_on_the_audit_log_too(): void
    {
        $world = $this->makeWorld();
        $manager = $this->role($this->makeUser([], $world['sacco']), Roles::QUEUE_SUPERVISOR);

        Sanctum::actingAs($manager);

        $this->putJson('/api/v1/auth/mobile/stage', ['terminus_id' => $world['terminus']->id])->assertOk();

        // A separate action from the admin path, so the log says which of the
        // two moved them -- the manager, or the office.
        $row = AuditLog::where('action', 'sacco.stage_manager.self_posted')->latest('id')->first();
        $this->assertNotNull($row);
        $this->assertSame((int) $manager->id, (int) $row->data['user_id']);
        $this->assertNull($row->data['from_terminus_id']);
        $this->assertSame((int) $world['terminus']->id, (int) $row->data['to_terminus_id']);
    }

    #[Test]
    public function a_manager_cannot_pick_another_saccos_stage(): void
    {
        $mine = $this->makeWorld();
        $theirs = $this->makeWorld();
        $manager = $this->role($this->makeUser([], $mine['sacco']), Roles::QUEUE_SUPERVISOR);

        Sanctum::actingAs($manager);

        // The whole guard: the id is resolved through the caller's own SACCO,
        // so a hand-rolled request cannot reach another SACCO's takings.
        $this->putJson('/api/v1/auth/mobile/stage', ['terminus_id' => $theirs['terminus']->id])
            ->assertStatus(422);

        $this->assertNull($manager->fresh()->stage_terminus_id);
    }

    #[Test]
    public function a_pick_with_no_stage_on_it_is_refused(): void
    {
        $world = $this->makeWorld();
        $manager = $this->role($this->makeUser([], $world['sacco']), Roles::QUEUE_SUPERVISOR);
        $manager->forceFill(['stage_terminus_id' => $world['terminus']->id])->save();

        Sanctum::actingAs($manager->fresh());

        // Unlike the admin path, null is not "take me off the stage": it would
        // only lock the manager's own screen behind a 409 they cannot clear.
        $this->putJson('/api/v1/auth/mobile/stage', ['terminus_id' => null])->assertStatus(422);

        $this->assertSame((int) $world['terminus']->id, (int) $manager->fresh()->stage_terminus_id);
    }

    #[Test]
    public function the_managers_screen_shows_the_stage_and_the_list(): void
    {
        $world = $this->makeWorld();
        $manager = $this->role($this->makeUser([], $world['sacco']), Roles::QUEUE_SUPERVISOR);
        $manager->forceFill(['stage_terminus_id' => $world['terminus']->id])->save();

        Sanctum::actingAs($manager->fresh());

        $this->getJson('/api/v1/auth/mobile/stage')
            ->assertOk()
            ->assertJsonPath('stage.id', (int) $world['terminus']->id)
            ->assertJsonPath('stages.0.id', (int) $world['terminus']->id);
    }

    #[Test]
    public function an_unposted_manager_gets_the_list_to_pick_from(): void
    {
        $world = $this->makeWorld();
        $manager = $this->role($this->makeUser([], $world['sacco']), Roles::QUEUE_SUPERVISOR);

        Sanctum::actingAs($manager);

        // The first-run screen: no stage yet, but something to tap.
        $this->getJson('/api/v1/auth/mobile/stage')
            ->assertOk()
            ->assertJsonPath('stage', null)
            ->assertJsonPath('stages.0.id', (int) $world['terminus']->id);
    }

    #[Test]
    public function the_mobile_picker_lists_only_this_saccos_stages(): void
    {
        $mine = $this->makeWorld();
        $theirs = $this->makeWorld();

        $second = $this->makeTerminus($this->makePlace('Ruiru Stage'));
        SaccoTerminus::create([
            'terminus_id' => $second->id,
            'sacco_id' => $mine['sacco']->id,
            'user_id' => $mine['owner']->id,
        ]);

        Sanctum::actingAs($this->role($this->makeUser([], $mine['sacco']), Roles::QUEUE_SUPERVISOR));

        $ids = $this->getJson('/api/v1/auth/mobile/stage')->assertOk()->json('stages.*.id');

        $this->assertContains((int) $mine['terminus']->id, $ids);
        $this->assertContains((int) $second->id, $ids);
        $this->assertNotContains((int) $theirs['terminus']->id, $ids);
    }

    #[Test]
    public function someone_without_a_stage_role_cannot_pick_one(): void
    {
        $world = $this->makeWorld();
        $nobody = $this->makeUser([], $world['sacco']); // no Queue Supervisor role

        Sanctum::actingAs($nobody);

        $this->putJson('/api/v1/auth/mobile/stage', ['terminus_id' => $world['terminus']->id])
            ->assertStatus(403);

        $this->assertNull($nobody->fresh()->stage_terminus_id);
    }

    #[Test]
    public function another_saccos_stage_is_refused(): void
    {
        $mine = $this->makeWorld();
        $theirs = $this->makeWorld();
        $manager = $this->role($this->makeUser([], $mine['sacco']), Roles::QUEUE_SUPERVISOR);

        Sanctum::actingAs($this->makeUser(['Edit Sacco Members'], $mine['sacco']));

        $this->postJson('/api/v1/auth/crew/'.$manager->id.'/stage', [
            'terminus_id' => $theirs['terminus']->id,
        ])->assertStatus(422);

        $this->assertNull($manager->fresh()->stage_terminus_id);
    }

    #[Test]
    public function a_stage_cannot_be_given_to_someone_who_does_not_work_one(): void
    {
        $world = $this->makeWorld();
        $driver = $this->makeUser([], $world['sacco']); // no Queue Supervisor role

        Sanctum::actingAs($this->makeUser(['Edit Sacco Members'], $world['sacco']));

        $this->postJson('/api/v1/auth/crew/'.$driver->id.'/stage', [
            'terminus_id' => $world['terminus']->id,
        ])->assertStatus(422);

        $this->assertNull($driver->fresh()->stage_terminus_id);
    }

    #[Test]
    public function a_manager_from_another_sacco_is_not_found(): void
    {
        $mine = $this->makeWorld();
        $theirs = $this->makeWorld();
        $outsider = $this->role($this->makeUser([], $theirs['sacco']), Roles::QUEUE_SUPERVISOR);

        Sanctum::actingAs($this->makeUser(['Edit Sacco Members'], $mine['sacco']));

        $this->postJson('/api/v1/auth/crew/'.$outsider->id.'/stage', [
            'terminus_id' => $mine['terminus']->id,
        ])->assertStatus(404);
    }

    #[Test]
    public function a_null_terminus_takes_them_off_the_stage(): void
    {
        $world = $this->makeWorld();
        $manager = $this->role($this->makeUser([], $world['sacco']), Roles::QUEUE_SUPERVISOR);
        $manager->forceFill(['stage_terminus_id' => $world['terminus']->id])->save();

        Sanctum::actingAs($this->makeUser(['Edit Sacco Members'], $world['sacco']));

        $this->postJson('/api/v1/auth/crew/'.$manager->id.'/stage', ['terminus_id' => null])
            ->assertOk()
            ->assertJsonPath('stage', null);

        $this->assertNull($manager->fresh()->stage_terminus_id);
    }

    #[Test]
    public function the_dashboard_picker_lists_only_this_saccos_stages(): void
    {
        $mine = $this->makeWorld();
        $theirs = $this->makeWorld();

        // A second stage for my SACCO, so the list is not trivially one row.
        $second = $this->makeTerminus($this->makePlace('Ruiru Stage'));
        SaccoTerminus::create([
            'terminus_id' => $second->id,
            'sacco_id' => $mine['sacco']->id,
            'user_id' => $mine['owner']->id,
        ]);

        Sanctum::actingAs($this->makeUser(['View Vehicle Users'], $mine['sacco']));

        $ids = $this->getJson('/api/v1/auth/crew/stages')->assertOk()->json('stages.*.id');

        $this->assertContains((int) $mine['terminus']->id, $ids);
        $this->assertContains((int) $second->id, $ids);
        $this->assertNotContains((int) $theirs['terminus']->id, $ids);
    }
}
