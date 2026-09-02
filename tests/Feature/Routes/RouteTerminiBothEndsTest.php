<?php

declare(strict_types=1);

namespace Tests\Feature\Routes;

use App\Models\SaccoTerminus;
use App\Models\Terminus;
use Illuminate\Support\Facades\Artisan;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Queues\QueueTestCase;

/**
 * A route has two termini, not one.
 *
 * The bus departs from the origin and turns round at the destination, and both
 * are stages where it queues. Provisioning only the origin left NICCO with two
 * termini for three routes — Alsops, Thika Main Stage and Ngong had none — so a
 * crew reaching the far end had nowhere to join a queue for the return leg, and
 * the driver's terminus picker offered two entries for three routes.
 */
final class RouteTerminiBothEndsTest extends QueueTestCase
{
    #[Test]
    public function building_a_route_provisions_a_terminus_at_each_end(): void
    {
        $world = $this->makeWorld();
        Sanctum::actingAs($this->makeUser(['Add Routes'], $world['sacco']));

        $from = $this->makePlace('Origin stage');
        $to = $this->makePlace('Far end stage');

        $this->postJson('/api/auth/saccos/routes/build', [
            'name' => 'Origin stage - Far end stage',
            'fare' => 120,
            'stops' => [
                ['place_id' => $from->id],
                ['place_id' => $to->id],
            ],
        ])->assertStatus(201);

        foreach ([$from, $to] as $place) {
            $terminus = Terminus::withoutGlobalScopes()->where('place_id', $place->id)->first();
            $this->assertNotNull($terminus, $place->name.' has no terminus');

            $this->assertTrue(
                SaccoTerminus::withoutGlobalScopes()
                    ->where('sacco_id', $world['sacco']->id)
                    ->where('terminus_id', $terminus->id)->exists(),
                $place->name.' is not linked to the SACCO'
            );
        }
    }

    #[Test]
    public function the_backfill_gives_an_existing_route_its_missing_far_end(): void
    {
        $world = $this->makeWorld(); // terminus exists at the origin only
        $operator = $this->makeUser([], $world['sacco']);

        $this->assertNull(
            Terminus::withoutGlobalScopes()->where('place_id', $world['to']->id)->first(),
            'precondition: the destination has no terminus'
        );

        Artisan::call('termini:backfill', [
            '--sacco' => $world['sacco']->id,
            '--user' => $operator->id,
        ]);

        $far = Terminus::withoutGlobalScopes()->where('place_id', $world['to']->id)->first();
        $this->assertNotNull($far, 'the destination should now be a terminus');
        $this->assertTrue(
            SaccoTerminus::withoutGlobalScopes()
                ->where('sacco_id', $world['sacco']->id)
                ->where('terminus_id', $far->id)->exists()
        );
    }

    #[Test]
    public function a_stage_shared_by_two_routes_is_not_duplicated(): void
    {
        $world = $this->makeWorld();
        $operator = $this->makeUser([], $world['sacco']);

        // A second route out of the same origin, as NICCO has from Nairobi CBD.
        $other = $this->makePlace('Second destination');
        $second = $this->makeRoute($world['from'], $other);
        $second->forceFill(['sacco_id' => $world['sacco']->id])->save();
        $this->makeSaccoRoute($world['sacco'], $second, $operator, 90);

        Artisan::call('termini:backfill', ['--sacco' => $world['sacco']->id, '--user' => $operator->id]);

        // One kerb, one row — inventing a second is how you end up with 41
        // terminus rows meaning about 20 real stages.
        $this->assertSame(1, Terminus::withoutGlobalScopes()->where('place_id', $world['from']->id)->count());
    }

    #[Test]
    public function the_backfill_refuses_without_an_author(): void
    {
        $world = $this->makeWorld();

        $this->assertSame(1, Artisan::call('termini:backfill', ['--sacco' => $world['sacco']->id]));
    }

    #[Test]
    public function the_backfill_writes_nothing_on_a_dry_run(): void
    {
        $world = $this->makeWorld();
        $operator = $this->makeUser([], $world['sacco']);

        Artisan::call('termini:backfill', [
            '--sacco' => $world['sacco']->id, '--user' => $operator->id, '--dry-run' => true,
        ]);

        $this->assertNull(Terminus::withoutGlobalScopes()->where('place_id', $world['to']->id)->first());
    }
}
