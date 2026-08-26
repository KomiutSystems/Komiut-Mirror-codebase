<?php

declare(strict_types=1);

namespace Tests\Feature\Queues;

use App\Models\Queue;
use App\Models\SaccoTerminus;
use App\Models\User;
use App\Models\VehicleUser;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;

/**
 * Queue integrity — the three go-live defects in one place:
 *
 *   A. queue_number is a string sorted LEXICALLY, so 'QN-10' fell before 'QN-2'
 *      and FIFO broke past nine vehicles. Fixed by an integer `position` and
 *      ordering the dispatch list on it.
 *   B. position = count()+1 with no lock / no constraint, so concurrent joins
 *      collided on the same slot. Fixed by a locked assignment plus a UNIQUE
 *      index on (terminus, route, business-day, position).
 *   C. join() never checked that the route/terminus belonged to the driver's
 *      SACCO, so any brand route was accepted and the fare silently fell to 0.
 *
 * makeWorld() seeds a sacco_routes row for the world route but NOT a
 * sacco_termini row, so the happy paths here add one explicitly.
 */
final class QueueIntegrityTest extends QueueTestCase
{
    /** A driver holding Edit Queues, actively assigned to the world's vehicle. */
    private function makeAssignedDriver(array $world): User
    {
        $driver = $this->makeUser(['Edit Queues'], $world['sacco']);
        VehicleUser::create([
            'user_id' => $driver->id,
            'vehicle_id' => $world['vehicle']->id,
            'sacco_id' => $world['sacco']->id,
            'status' => true,
        ]);

        return $driver;
    }

    /** Assign a terminus to a SACCO (the sacco_termini membership join() reads). */
    private function assignTerminus(array $world, ?int $terminusId = null): SaccoTerminus
    {
        return SaccoTerminus::create([
            'sacco_id' => $world['sacco']->id,
            'terminus_id' => $terminusId ?? $world['terminus']->id,
            'user_id' => $world['owner']->id,
            'geofence_radius' => 100,
        ]);
    }

    // ---- C: SACCO ownership of route and terminus -----------------------------

    #[Test]
    public function joining_a_route_the_sacco_does_not_run_is_rejected_and_creates_no_queue(): void
    {
        $world = $this->makeWorld();
        $this->makeQueueStatus('Pending', 'Pending');
        $driver = $this->makeAssignedDriver($world);

        // A second route the SACCO has NO sacco_routes row for. Its terminus is a
        // valid origin so the origin check passes and we reach the route check.
        $origin = $this->makePlace('Foreign Origin '.$this->nextSequence());
        $dest = $this->makePlace('Foreign Dest '.$this->nextSequence());
        $foreignRoute = $this->makeRoute($origin, $dest, $world['sacco']);
        $foreignTerminus = $this->makeTerminus($origin);
        // Terminus IS assigned to the SACCO, to prove it is specifically the route
        // that is refused (the route check runs before the terminus check).
        $this->assignTerminus($world, $foreignTerminus->id);

        Sanctum::actingAs($driver);

        $this->postJson('/api/auth/queues/join', [
            'terminus_id' => $foreignTerminus->id,
            'route_id' => $foreignRoute->id,
        ])->assertStatus(422)
            ->assertJson(['error' => 'This route is not offered by your SACCO.']);

        // No fare-0 queue was created.
        $this->assertSame(0, Queue::count());
    }

    #[Test]
    public function joining_a_terminus_not_assigned_to_the_sacco_is_rejected(): void
    {
        $world = $this->makeWorld();
        $this->makeQueueStatus('Pending', 'Pending');
        $driver = $this->makeAssignedDriver($world);
        // A second terminus AT the route origin but NOT assigned to the SACCO
        // (makeWorld already assigns the world's own terminus, so we need a fresh
        // one to exercise the sacco_termini gate).
        $unassigned = $this->makeTerminus($world['from']);
        Sanctum::actingAs($driver);

        $this->postJson('/api/auth/queues/join', [
            'terminus_id' => $unassigned->id,
            'route_id' => $world['route']->id,
        ])->assertStatus(422)
            ->assertJson(['error' => 'This terminus is not assigned to your SACCO.']);

        $this->assertSame(0, Queue::count());
    }

    #[Test]
    public function a_valid_join_prices_the_queue_from_the_sacco_fare(): void
    {
        $world = $this->makeWorld();
        $this->makeQueueStatus('Pending', 'Pending');
        $driver = $this->makeAssignedDriver($world);
        $this->assignTerminus($world);
        Sanctum::actingAs($driver);

        $this->postJson('/api/auth/queues/join', [
            'terminus_id' => $world['terminus']->id,
            'route_id' => $world['route']->id,
        ])->assertStatus(201);

        $queue = Queue::firstOrFail();
        // Priced from the SACCO's 200 flat fare (never the silent 0 fallback).
        $this->assertEquals(200, $queue->amount);
        // The integer slot is set alongside the display number.
        $this->assertSame(1, $queue->position);
        $this->assertSame('QN-1', $queue->queue_number);
    }

    // ---- D: idempotent re-join ------------------------------------------------

    #[Test]
    public function re_joining_the_same_route_stays_idempotent(): void
    {
        $world = $this->makeWorld();
        $this->makeQueueStatus('Pending', 'Pending');
        $driver = $this->makeAssignedDriver($world);
        $this->assignTerminus($world);
        Sanctum::actingAs($driver);

        $first = $this->postJson('/api/auth/queues/join', [
            'terminus_id' => $world['terminus']->id,
            'route_id' => $world['route']->id,
        ])->assertStatus(201)->json('queue.id');

        $this->postJson('/api/auth/queues/join', [
            'terminus_id' => $world['terminus']->id,
            'route_id' => $world['route']->id,
        ])->assertOk()->assertJsonPath('queue.id', $first);

        // No second slot was consumed on the idempotent re-join.
        $this->assertSame(1, Queue::count());
    }

    // ---- A: numeric (not lexical) ordering ------------------------------------

    #[Test]
    public function the_dispatch_list_orders_by_integer_position_past_nine(): void
    {
        $world = $this->makeWorld();
        $pending = $this->makeQueueStatus('Pending', 'Pending');

        // Twelve queues at one terminus+route, positions 1..12. Under the old
        // lexical order on queue_number, 'QN-10'..'QN-12' would sort between
        // 'QN-1' and 'QN-2'; the integer position must keep them 1..12.
        for ($i = 1; $i <= 12; $i++) {
            $queue = $this->makeQueue(
                $world['vehicle'], $world['terminus'], $world['route'], $pending, $world['owner'], 'QN-'.$i
            );
            $queue->position = $i;
            $queue->save();
        }

        $viewer = $this->makeUser(['View Queues'], $world['sacco']);
        Sanctum::actingAs($viewer);

        $positions = collect($this->getJson('/api/auth/queues')->assertOk()->json('queues'))
            ->pluck('position')
            ->all();

        $this->assertSame(range(1, 12), $positions);
    }

    #[Test]
    public function joins_at_one_terminus_and_route_take_successive_positions(): void
    {
        $world = $this->makeWorld();
        $this->makeQueueStatus('Pending', 'Pending');
        $this->assignTerminus($world);

        // Three different drivers/vehicles queue the same terminus+route in turn.
        for ($i = 1; $i <= 3; $i++) {
            $vehicle = $this->makeVehicle($world['sacco'], $world['owner'], $world['seat']);
            $driver = $this->makeUser(['Edit Queues'], $world['sacco']);
            VehicleUser::create([
                'user_id' => $driver->id,
                'vehicle_id' => $vehicle->id,
                'sacco_id' => $world['sacco']->id,
                'status' => true,
            ]);
            Sanctum::actingAs($driver);

            $this->postJson('/api/auth/queues/join', [
                'terminus_id' => $world['terminus']->id,
                'route_id' => $world['route']->id,
            ])->assertStatus(201)
                ->assertJsonPath('queue.position', $i)
                ->assertJsonPath('queue.queue_number', 'QN-'.$i);
        }

        $this->assertSame([1, 2, 3], Queue::orderBy('id')->pluck('position')->all());
    }

    // ---- B: the DB-level slot guarantee ---------------------------------------

    #[Test]
    public function the_unique_index_rejects_two_queues_sharing_a_slot(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('The functional slot-uniqueness index is PostgreSQL-only.');
        }

        $world = $this->makeWorld();
        $pending = $this->makeQueueStatus('Pending', 'Pending');

        $first = $this->makeQueue(
            $world['vehicle'], $world['terminus'], $world['route'], $pending, $world['owner'], 'QN-1'
        );
        $first->position = 1;
        $first->save();

        // A second queue at the same terminus+route+day trying to take slot 1.
        $collision = $this->makeQueue(
            $world['vehicle'], $world['terminus'], $world['route'], $pending, $world['owner'], 'QN-1'
        );
        $collision->position = 1;

        $this->expectException(QueryException::class);
        $collision->save();
    }
}
