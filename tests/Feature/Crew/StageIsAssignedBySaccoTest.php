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
 * A stage manager is posted to their stage by the SACCO, not by themselves.
 *
 * The stage decides whose takings the manager's app shows, so choosing it is
 * an authorization change. It shipped as PUT mobile/stage -- the manager
 * picking from a list in their own app, with no permission gate and no record
 * that it happened. It belongs on the crew screen, gated and audited.
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
    public function a_manager_cannot_declare_their_own_stage(): void
    {
        $world = $this->makeWorld();
        $manager = $this->role($this->makeUser([], $world['sacco']), Roles::QUEUE_SUPERVISOR);

        Sanctum::actingAs($manager);

        $this->putJson('/api/v1/auth/mobile/stage', ['terminus_id' => $world['terminus']->id])
            ->assertStatus(403)
            ->assertJsonPath('error', 'Your SACCO sets your stage. Ask your SACCO admin to post you to one.');

        $this->assertNull($manager->fresh()->stage_terminus_id);
    }

    #[Test]
    public function the_managers_screen_shows_the_posting_and_offers_no_choice(): void
    {
        $world = $this->makeWorld();
        $manager = $this->role($this->makeUser([], $world['sacco']), Roles::QUEUE_SUPERVISOR);
        $manager->forceFill(['stage_terminus_id' => $world['terminus']->id])->save();

        Sanctum::actingAs($manager->fresh());

        $this->getJson('/api/v1/auth/mobile/stage')
            ->assertOk()
            ->assertJsonPath('stage.id', (int) $world['terminus']->id)
            ->assertJsonPath('assigned_by_sacco', true)
            // The picker list is gone: a manager cannot act on it.
            ->assertJsonMissingPath('stages');
    }

    #[Test]
    public function an_unposted_manager_is_told_so_rather_than_shown_a_list(): void
    {
        $world = $this->makeWorld();
        $manager = $this->role($this->makeUser([], $world['sacco']), Roles::QUEUE_SUPERVISOR);

        Sanctum::actingAs($manager);

        $this->getJson('/api/v1/auth/mobile/stage')
            ->assertOk()
            ->assertJsonPath('stage', null)
            ->assertJsonMissingPath('stages');
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
