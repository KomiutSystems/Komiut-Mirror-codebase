<?php

declare(strict_types=1);

namespace Tests\Feature\Super;

use App\Enums\UserType;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Queues\QueueTestCase;

/**
 * Super-admin routes + termini reads.
 */
final class RoutesTerminiTest extends QueueTestCase
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
    public function it_lists_routes_with_from_and_to_places(): void
    {
        $from = $this->makePlace('Nairobi CBD');
        $to = $this->makePlace('Thika');
        $route = $this->makeRoute($from, $to);

        Sanctum::actingAs($this->superAdmin());

        $response = $this->getJson('/api/v1/super/routes')->assertOk();
        $row = collect($response->json('data'))->firstWhere('id', $route->id);

        $this->assertNotNull($row);
        $this->assertSame($from->id, $row['from']['id']);
        $this->assertSame($to->id, $row['to']['id']);
        $this->assertArrayHasKey('status', $row);
    }

    #[Test]
    public function it_filters_routes_by_sacco_id_via_the_sacco_routes_pivot(): void
    {
        $sacco = $this->makeSacco();
        $owner = $this->makeUser([], $sacco);

        $from = $this->makePlace('A');
        $to = $this->makePlace('B');
        $ownedRoute = $this->makeRoute($from, $to);
        $this->makeSaccoRoute($sacco, $ownedRoute, $owner);

        $from2 = $this->makePlace('C');
        $to2 = $this->makePlace('D');
        $otherRoute = $this->makeRoute($from2, $to2);

        Sanctum::actingAs($this->superAdmin());

        $response = $this->getJson('/api/v1/super/routes?sacco_id='.$sacco->id)->assertOk();
        $ids = collect($response->json('data'))->pluck('id');

        $this->assertTrue($ids->contains($ownedRoute->id));
        $this->assertFalse($ids->contains($otherRoute->id));
    }

    #[Test]
    public function it_lists_termini_with_their_place(): void
    {
        $place = $this->makePlace('Nairobi CBD');
        $terminus = $this->makeTerminus($place);

        Sanctum::actingAs($this->superAdmin());

        $response = $this->getJson('/api/v1/super/termini')->assertOk();
        $row = collect($response->json('data'))->firstWhere('id', $terminus->id);

        $this->assertNotNull($row);
        $this->assertSame($place->id, $row['place']['id']);
        $this->assertArrayHasKey('status', $row);
    }
}
