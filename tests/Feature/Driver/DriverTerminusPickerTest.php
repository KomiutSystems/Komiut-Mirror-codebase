<?php

declare(strict_types=1);

namespace Tests\Feature\Driver;

use App\Models\SaccoTerminus;
use App\Models\Terminus;
use App\Services\Driver\AvailableTermini;
use Tests\Feature\Queues\QueueTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * The driver's terminus picker must answer the same question the dashboard does.
 *
 * `sacco_termini` is the SACCO's own statement of which stages are theirs, and
 * TerminusAPIController has always read it first. This service ignored it and
 * re-derived the list from route ORIGINS, so a SACCO could link a terminus at
 * Thika, see it on the dashboard, and find the driver's picker still offering
 * only the origins — which is exactly what NICCO hit after its far ends were
 * provisioned.
 */
final class DriverTerminusPickerTest extends QueueTestCase
{
    #[Test]
    public function a_linked_terminus_that_is_no_routes_origin_still_appears(): void
    {
        $world = $this->makeWorld();
        $driver = $this->makeUser([], $world['sacco']);

        // The far end of the route — a real stage, but nobody's origin.
        $farEnd = Terminus::create([
            'name' => 'Thika Main Stage', 'place_id' => $world['to']->id, 'status' => true,
        ]);
        SaccoTerminus::create([
            'terminus_id' => $farEnd->id, 'sacco_id' => $world['sacco']->id, 'user_id' => $world['owner']->id,
        ]);

        $names = app(AvailableTermini::class)->forDriver($driver)
            ->map(fn (array $row) => $row['terminus']->name)->all();

        $this->assertContains('Thika Main Stage', $names, 'the SACCO linked it; the driver must see it');
        $this->assertContains($world['terminus']->name, $names, 'the origin is still there');
    }

    #[Test]
    public function route_origins_are_the_fallback_not_the_answer(): void
    {
        $world = $this->makeWorld();
        $driver = $this->makeUser([], $world['sacco']);

        // makeWorld links the origin terminus; drop the link and the service
        // should fall back to deriving from routes rather than showing nothing.
        SaccoTerminus::withoutGlobalScopes()->where('sacco_id', $world['sacco']->id)->delete();

        $names = app(AvailableTermini::class)->forDriver($driver)
            ->map(fn (array $row) => $row['terminus']->name)->all();

        $this->assertContains($world['terminus']->name, $names);
    }
}
