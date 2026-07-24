<?php

declare(strict_types=1);

namespace Tests\Feature\Queues;

use App\Models\RouteStage;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;

/**
 * Regression coverage for the permission middleware added to
 * App\Http\Controllers\APIs\Dashboard\Routes\RouteAPIController::addRouteStage
 * and ::addRouteStageCoords, previously reachable by any authenticated user.
 */
final class RouteStagesPermissionTest extends QueueTestCase
{
    #[Test]
    public function adding_a_route_stage_requires_add_or_edit_routes_permission(): void
    {
        $world = $this->makeWorld();
        Sanctum::actingAs($this->makeUser([], $world['sacco']));

        $this->postJson('/api/auth/routes/stages/add', [
            'id' => 0,
            'route_id' => $world['route']->id,
            'place' => $world['to']->name,
            'status' => 1,
        ])->assertStatus(403);
    }

    #[Test]
    public function a_user_with_edit_routes_can_add_a_route_stage(): void
    {
        $world = $this->makeWorld();
        $place = $this->makePlace('Ruiru ' . $this->nextSequence());
        Sanctum::actingAs($this->makeUser(['Edit Routes'], $world['sacco']));

        $this->postJson('/api/auth/routes/stages/add', [
            'id' => 0,
            'route_id' => $world['route']->id,
            'place' => $place->name,
            'status' => 1,
        ])->assertOk();

        $this->assertSame(1, RouteStage::where('place_id', $place->id)->count());
    }

    #[Test]
    public function adding_route_stage_coordinates_requires_edit_routes_permission(): void
    {
        $world = $this->makeWorld();
        $stage = $world['stages'][0];
        Sanctum::actingAs($this->makeUser([], $world['sacco']));

        $this->postJson('/api/auth/routes/stages/coords/add', [
            'id' => $stage->id,
            'longitude' => 36.9,
            'latitude' => -1.3,
        ])->assertStatus(403);
    }
}
