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
 *      Since 2026-10-06 join() takes a stage only, so the route half is moot:
 *      a route sent is ignored and the line carries no fare.
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
    public function a_route_sent_with_a_join_never_prices_or_labels_the_queue(): void
    {
        // Joining a queue took a route until 2026-10-06, and refused one the
        // SACCO did not run so the fare could not fall to 0. It takes a stage
        // now; an older app's route is ignored -- even a foreign one -- and the
        // place in the line carries no route and no fare at all.
        $world = $this->makeWorld();
        $this->makeQueueStatus('Pending', 'Pending');
        $driver = $this->makeAssignedDriver($world);
        $foreignRoute = $this->makeRoute(
            $this->makePlace('Foreign Origin '.$this->nextSequence()),
            $this->makePlace('Foreign Dest '.$this->nextSequence()),
            $world['sacco'],
        );

        Sanctum::actingAs($driver);

        $this->postJson('/api/auth/queues/join', [
            'terminus_id' => $world['terminus']->id,
            'route_id' => $foreignRoute->id,
        ])->assertStatus(201);

        $queue = Queue::firstOrFail();
        $this->assertNull($queue->route_id);
        $this->assertEquals(0, $queue->amount);
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
    public function a_valid_join_takes_the_first_place_in_the_stage_line(): void
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
        // A place in a line is not sold: the fare lives on the live run.
        $this->assertEquals(0, $queue->amount);
        $this->assertSame(Queue::KIND_STAGE, $queue->kind);
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
                $world['vehicle'], $world['terminus'], $world['route'], $pending, $world['owner'], 'QN-'.$i, Queue::KIND_STAGE
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

    #[Test]
    public function the_unique_index_guards_a_stage_line_with_no_route_too(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('The functional slot-uniqueness index is PostgreSQL-only.');
        }

        // NULL route ids never collide in the per-route index, so the route-less
        // stage line has its own: (terminus, day, position) where route is null.
        $world = $this->makeWorld();
        $pending = $this->makeQueueStatus('Pending', 'Pending');
        $row = fn () => Queue::create([
            'kind' => Queue::KIND_STAGE, 'queue_number' => 'QN-1', 'vehicle_id' => $world['vehicle']->id,
            'terminus_id' => $world['terminus']->id, 'queue_status_id' => $pending->id, 'route_id' => null,
            'user_id' => $world['owner']->id, 'amount' => 0, 'start_time' => now(), 'queue_type' => false,
        ]);

        $first = $row();
        $first->position = 1;
        $first->save();

        $collision = $row();
        $collision->position = 1;

        $this->expectException(QueryException::class);
        $collision->save();
    }
}
