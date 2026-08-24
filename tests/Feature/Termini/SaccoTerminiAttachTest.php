<?php

declare(strict_types=1);

namespace Tests\Feature\Termini;

use App\Enums\UserType;
use App\Models\SaccoTerminus;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Queues\QueueTestCase;

/**
 * The super console's sacco_termini writer.
 *
 * `sacco_termini` had three readers and no writer at all, and every one of them
 * fails closed — so a terminus created on the reference surface stayed invisible
 * to every SACCO on the platform. These cover the link being created, updated,
 * removed, and gated.
 */
final class SaccoTerminiAttachTest extends QueueTestCase
{
    /**
     * A platform operator. Superadmin-ness clears the `super` middleware but
     * grants no capability of its own (AuthServiceProvider deliberately has no
     * Gate::before bypass), so the permissions are handed out explicitly.
     *
     * @param  array<int, string>  $permissions
     */
    private function operator(array $permissions): User
    {
        $user = $this->makeUser();
        $user->forceFill(['type' => UserType::Superadmin])->save();

        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        if ($permissions !== []) {
            $user->givePermissionTo($permissions);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user;
    }

    #[Test]
    public function an_operator_attaches_a_terminus_to_a_sacco_and_detaches_it(): void
    {
        $sacco = $this->makeSacco();
        $terminus = $this->makeTerminus($this->makePlace('Nairobi CBD'));
        $operator = $this->operator(['Add Termini Saccos', 'Edit Termini Saccos', 'View Termini Saccos']);

        Sanctum::actingAs($operator);

        $response = $this->postJson('/api/v1/super/saccos/'.$sacco->id.'/termini', [
            'terminus_id' => $terminus->id,
            'geofence_radius' => 250,
        ])->assertStatus(201)
            ->assertJsonPath('link.terminus.id', $terminus->id);

        // json_encode renders 250.0 as 250, so compare numerically.
        $this->assertEqualsWithDelta(250.0, (float) $response->json('link.geofence_radius'), 0.001);

        // user_id is NOT NULL with no default; the acting operator is recorded.
        $this->assertDatabaseHas('sacco_termini', [
            'sacco_id' => $sacco->id,
            'terminus_id' => $terminus->id,
            'user_id' => $operator->id,
            'geofence_radius' => 250,
        ]);

        $this->getJson('/api/v1/super/saccos/'.$sacco->id.'/termini')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.terminus.id', $terminus->id);

        $this->deleteJson('/api/v1/super/saccos/'.$sacco->id.'/termini/'.$terminus->id)
            ->assertOk()
            ->assertJsonPath('detached', 1);

        $this->assertDatabaseMissing('sacco_termini', [
            'sacco_id' => $sacco->id,
            'terminus_id' => $terminus->id,
        ]);
    }

    #[Test]
    public function attaching_the_same_terminus_twice_updates_the_link_instead_of_duplicating_it(): void
    {
        $sacco = $this->makeSacco();
        $terminus = $this->makeTerminus($this->makePlace('Thika'));

        Sanctum::actingAs($this->operator(['Add Termini Saccos', 'Edit Termini Saccos']));

        $url = '/api/v1/super/saccos/'.$sacco->id.'/termini';

        $this->postJson($url, ['terminus_id' => $terminus->id, 'geofence_radius' => 100])
            ->assertStatus(201);
        // (sacco_id, terminus_id) carries only an index, never a UNIQUE
        // constraint, so nothing but this upsert stops a second row.
        $this->postJson($url, ['terminus_id' => $terminus->id, 'geofence_radius' => 400])
            ->assertOk();

        $this->assertSame(1, SaccoTerminus::withoutGlobalScopes()
            ->where('sacco_id', $sacco->id)->where('terminus_id', $terminus->id)->count());
        $this->assertEqualsWithDelta(400.0, (float) SaccoTerminus::withoutGlobalScopes()
            ->where('sacco_id', $sacco->id)->first()->geofence_radius, 0.001);
    }

    #[Test]
    public function re_attaching_without_a_radius_leaves_the_configured_one_alone(): void
    {
        $sacco = $this->makeSacco();
        $terminus = $this->makeTerminus($this->makePlace('Ruiru'));

        Sanctum::actingAs($this->operator(['Add Termini Saccos', 'Edit Termini Saccos']));

        $url = '/api/v1/super/saccos/'.$sacco->id.'/termini';
        $this->postJson($url, ['terminus_id' => $terminus->id, 'geofence_radius' => 300])->assertStatus(201);
        $this->postJson($url, ['terminus_id' => $terminus->id])->assertOk();

        $this->assertEqualsWithDelta(300.0, (float) SaccoTerminus::withoutGlobalScopes()
            ->where('sacco_id', $sacco->id)->first()->geofence_radius, 0.001);
    }

    #[Test]
    public function a_negative_geofence_radius_is_rejected_before_it_reaches_postgres(): void
    {
        $sacco = $this->makeSacco();
        $terminus = $this->makeTerminus($this->makePlace('Kikuyu'));

        Sanctum::actingAs($this->operator(['Add Termini Saccos', 'Edit Termini Saccos']));

        // The column is `double unsigned`; unvalidated this is a 500, not a 422.
        $this->postJson('/api/v1/super/saccos/'.$sacco->id.'/termini', [
            'terminus_id' => $terminus->id,
            'geofence_radius' => -5,
        ])->assertStatus(422);

        $this->assertDatabaseMissing('sacco_termini', ['sacco_id' => $sacco->id]);
    }

    #[Test]
    public function an_operator_without_the_termini_saccos_permission_is_refused(): void
    {
        $sacco = $this->makeSacco();
        $terminus = $this->makeTerminus($this->makePlace('Limuru'));

        // Clears the `super` middleware, holds neither Add nor Edit.
        Sanctum::actingAs($this->operator([]));

        $this->postJson('/api/v1/super/saccos/'.$sacco->id.'/termini', [
            'terminus_id' => $terminus->id,
        ])->assertStatus(403);

        $this->deleteJson('/api/v1/super/saccos/'.$sacco->id.'/termini/'.$terminus->id)
            ->assertStatus(403);

        $this->assertDatabaseMissing('sacco_termini', ['sacco_id' => $sacco->id]);
    }

    #[Test]
    public function a_non_super_caller_never_reaches_the_write_surface(): void
    {
        $sacco = $this->makeSacco();
        $terminus = $this->makeTerminus($this->makePlace('Ngong'));

        // Holding the permission is not enough: this is a platform surface.
        Sanctum::actingAs($this->makeUser(['Add Termini Saccos', 'Edit Termini Saccos'], $sacco));

        $this->postJson('/api/v1/super/saccos/'.$sacco->id.'/termini', [
            'terminus_id' => $terminus->id,
        ])->assertStatus(403);
    }

    #[Test]
    public function detaching_a_link_that_is_not_there_is_not_an_error(): void
    {
        $sacco = $this->makeSacco();
        $terminus = $this->makeTerminus($this->makePlace('Juja'));

        Sanctum::actingAs($this->operator(['Edit Termini Saccos']));

        $this->deleteJson('/api/v1/super/saccos/'.$sacco->id.'/termini/'.$terminus->id)
            ->assertOk()
            ->assertJsonPath('detached', 0);
    }

    #[Test]
    public function attaching_a_terminus_that_does_not_exist_is_a_422(): void
    {
        $sacco = $this->makeSacco();

        Sanctum::actingAs($this->operator(['Add Termini Saccos', 'Edit Termini Saccos']));

        $this->postJson('/api/v1/super/saccos/'.$sacco->id.'/termini', ['terminus_id' => 999999])
            ->assertStatus(422);
    }

    #[Test]
    public function attaching_to_a_sacco_that_does_not_exist_is_a_404(): void
    {
        $terminus = $this->makeTerminus($this->makePlace('Athi River'));

        Sanctum::actingAs($this->operator(['Add Termini Saccos', 'Edit Termini Saccos']));

        $this->postJson('/api/v1/super/saccos/999999/termini', ['terminus_id' => $terminus->id])
            ->assertStatus(404);
    }
}
