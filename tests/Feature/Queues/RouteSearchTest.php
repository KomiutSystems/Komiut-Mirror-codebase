<?php

declare(strict_types=1);

namespace Tests\Feature\Queues;

use App\Models\Place;
use Database\Seeders\NairobiRoutesSeeder;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;

/**
 * Point-first ("Uber with fixed stops") route search:
 * BookARideRoutesAPIController@getRoutes matches routes by pickup/dropoff place
 * ids, honouring direction (pickup must come before dropoff along the route).
 */
final class RouteSearchTest extends QueueTestCase
{
    #[Test]
    public function point_first_search_matches_by_place_ids_and_respects_direction(): void
    {
        $world = $this->makeWorld();                       // stages: from @0, to @40
        Sanctum::actingAs($this->makeUser([], $world['sacco']));

        $from = $world['from']->id;
        $to = $world['to']->id;

        $forward = $this->getJson("/api/auth/book_a_ride/routes?from_place_id={$from}&to_place_id={$to}")->assertOk();
        $this->assertContains($world['route']->id, collect($forward->json('routes'))->pluck('id')->all());

        // Reverse direction (pickup after dropoff) must not match.
        $reverse = $this->getJson("/api/auth/book_a_ride/routes?from_place_id={$to}&to_place_id={$from}")->assertOk();
        $this->assertNotContains($world['route']->id, collect($reverse->json('routes'))->pluck('id')->all());
    }

    #[Test]
    public function nairobi_seed_supports_a_cbd_to_thika_segment_search(): void
    {
        $this->seed(NairobiRoutesSeeder::class);
        Sanctum::actingAs($this->makeUser());

        $cbd = Place::where('name', 'Nairobi CBD')->first();
        $thika = Place::where('name', 'Thika')->first();
        $this->assertNotNull($cbd);
        $this->assertNotNull($thika);

        $res = $this->getJson("/api/auth/book_a_ride/routes?from_place_id={$cbd->id}&to_place_id={$thika->id}")->assertOk();

        $names = collect($res->json('routes'))->pluck('name')->all();
        $this->assertTrue(collect($names)->contains(fn ($n) => str_contains((string) $n, 'Thika')));
    }
}
