<?php

declare(strict_types=1);

namespace Tests\Feature\Routes;

use App\Enums\UserType;
use App\Models\SaccoTerminus;
use App\Models\Terminus;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Queues\QueueTestCase;

/**
 * A route you can actually depart from.
 *
 * `queues` requires a terminus whose place IS the route's origin — the driver
 * and the dispatcher both enforce it, and terminus_id is NOT NULL, so a missing
 * one fails as a 422 rather than degrading into something half-working. And a
 * booking needs a queue.
 *
 * So the route builder was producing routes that could never run a single trip.
 * NICCO's route 1973 had four stops, a fare, and no terminus anywhere near its
 * origin — and `sacco_termini` held ZERO rows across all 48 SACCOs after three
 * years, because the only thing that could write it was a superadmin-only
 * console. A SACCO admin building their own route could not attach one either.
 */
final class RouteTerminusTest extends QueueTestCase
{
    private const BUILD = '/api/v1/auth/saccos/routes/build';

    private function admin(): array
    {
        $sacco = $this->makeSacco();
        $u = $this->makeUser(['Add Routes', 'Edit Routes'], $sacco);
        $u->forceFill(['type' => UserType::Admin, 'sacco_id' => $sacco->id])->save();

        Sanctum::actingAs($u->fresh());

        return [$sacco, $u->fresh()];
    }

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        $n = $this->nextSequence();

        return array_merge([
            'name' => "CBD - Thika {$n}",
            'fare' => 150,
            'stops' => [
                ['name' => "Nairobi CBD {$n}", 'latitude' => -1.2864, 'longitude' => 36.8172],
                ['name' => "Ruiru {$n}", 'latitude' => -1.1500, 'longitude' => 36.9600],
                ['name' => "Thika {$n}", 'latitude' => -1.0333, 'longitude' => 37.0693],
            ],
        ], $overrides);
    }

    #[Test]
    public function building_a_route_creates_a_terminus_at_its_origin(): void
    {
        [$sacco] = $this->admin();

        $this->postJson(self::BUILD, $this->payload())->assertCreated();

        $route = \App\Models\Route::withoutGlobalScopes()->where('sacco_id', $sacco->id)->firstOrFail();

        $terminus = Terminus::withoutGlobalScopes()->where('place_id', $route->from_id)->first();

        $this->assertNotNull($terminus, 'a route with no terminus can never run a trip');
        $this->assertSame((int) $route->from_id, (int) $terminus->place_id, 'the terminus must be the route ORIGIN');
    }

    #[Test]
    public function the_sacco_is_attached_to_the_terminus_it_departs_from(): void
    {
        // The second half. The stage existing is not enough — the driver's
        // terminus picker and the queue check both read sacco_termini, and it
        // had zero rows platform-wide.
        [$sacco] = $this->admin();

        $this->postJson(self::BUILD, $this->payload())->assertCreated();

        $route = \App\Models\Route::withoutGlobalScopes()->where('sacco_id', $sacco->id)->firstOrFail();
        $terminus = Terminus::withoutGlobalScopes()->where('place_id', $route->from_id)->firstOrFail();

        $this->assertTrue(
            SaccoTerminus::withoutGlobalScopes()
                ->where('sacco_id', $sacco->id)
                ->where('terminus_id', $terminus->id)
                ->exists(),
            'the SACCO must be linked to the stage it works out of'
        );
    }

    #[Test]
    public function the_terminus_carries_the_stops_coordinates(): void
    {
        // A terminus with no coordinates cannot check whether a driver is
        // actually standing at it — which is what the geofence is for.
        [$sacco] = $this->admin();

        $this->postJson(self::BUILD, $this->payload())->assertCreated();

        $route = \App\Models\Route::withoutGlobalScopes()->where('sacco_id', $sacco->id)->firstOrFail();
        $terminus = Terminus::withoutGlobalScopes()->where('place_id', $route->from_id)->firstOrFail();

        $this->assertEqualsWithDelta(-1.2864, (float) $terminus->latitude, 0.0001);
        $this->assertEqualsWithDelta(36.8172, (float) $terminus->longitude, 0.0001);
    }

    #[Test]
    public function two_saccos_starting_at_the_same_stage_share_one_terminus(): void
    {
        // A terminus is a PLACE — one physical kerb that everyone stopping there
        // uses. Inventing a second row for the same stage is how you end up with
        // 41 termini that mean 20 stages. They get separate sacco_termini links
        // instead, which is exactly what that table is for.
        [$saccoA] = $this->admin();
        $shared = ['name' => 'Shared CBD Stage', 'latitude' => -1.2864, 'longitude' => 36.8172];

        $this->postJson(self::BUILD, $this->payload([
            'stops' => [$shared, ['name' => 'Ruiru A', 'latitude' => -1.15, 'longitude' => 36.96]],
        ]))->assertCreated();

        [$saccoB] = $this->admin();

        $this->postJson(self::BUILD, $this->payload([
            'stops' => [$shared, ['name' => 'Juja B', 'latitude' => -1.10, 'longitude' => 37.01]],
        ]))->assertCreated();

        $termini = Terminus::withoutGlobalScopes()->where('name', 'Shared CBD Stage')->get();
        $this->assertCount(1, $termini, 'one stage, one terminus row');

        $links = SaccoTerminus::withoutGlobalScopes()->where('terminus_id', $termini->first()->id)->get();
        $this->assertCount(2, $links, 'but a link each, so both SACCOs can work out of it');
    }

    #[Test]
    public function rebuilding_against_an_existing_stage_does_not_duplicate_the_link(): void
    {
        [$sacco] = $this->admin();
        $shared = ['name' => 'Repeat Stage', 'latitude' => -1.2864, 'longitude' => 36.8172];

        $this->postJson(self::BUILD, $this->payload([
            'stops' => [$shared, ['name' => 'Leg One', 'latitude' => -1.15, 'longitude' => 36.96]],
        ]))->assertCreated();

        $this->postJson(self::BUILD, $this->payload([
            'stops' => [$shared, ['name' => 'Leg Two', 'latitude' => -1.10, 'longitude' => 37.01]],
        ]))->assertCreated();

        $terminus = Terminus::withoutGlobalScopes()->where('name', 'Repeat Stage')->firstOrFail();

        $this->assertSame(
            1,
            SaccoTerminus::withoutGlobalScopes()
                ->where('sacco_id', $sacco->id)
                ->where('terminus_id', $terminus->id)
                ->count()
        );
    }
}
