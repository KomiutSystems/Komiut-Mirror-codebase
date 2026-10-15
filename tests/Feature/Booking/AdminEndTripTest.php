<?php

declare(strict_types=1);

namespace Tests\Feature\Booking;

use App\Enums\PaymentMethod;
use App\Models\Booking;
use App\Models\Queue;
use App\Models\QueueStatus;
use App\Models\User;
use App\Models\VehicleUser;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Queues\QueueTestCase;

/**
 * queues/{id}/end: the office ends a bus's trip when the crew cannot (a dead
 * phone, a driver who walked off) -- through the driver's own TripEnder, minus
 * any say over who boarded (decided 2026-09-12).
 */
final class AdminEndTripTest extends QueueTestCase
{
    private function trip(string $status = 'Active', string $kind = Queue::KIND_LIVE): array
    {
        $world = $this->makeWorld();
        if (! QueueStatus::where('status', 'Completed')->exists()) {
            $this->makeQueueStatus('Completed', 'Completed');
        }
        $queue = $this->makeQueue(
            $world['vehicle'], $world['terminus'], $world['route'],
            $this->makeQueueStatus($status.' '.$this->nextSequence(), $status), $world['owner'], 'QN-1', $kind,
        );

        return ['world' => $world, 'queue' => $queue];
    }

    private function admin(array $trip): User
    {
        return $this->makeUser(['Edit Queues', 'View Queues'], $trip['world']['sacco']);
    }

    private function paid(array $trip): Booking
    {
        $b = $this->makeBooking($trip['queue'], $this->makeUser(), $trip['world']['from'], $trip['world']['to']);
        $b->update(['paid' => true, 'payment_method' => PaymentMethod::Mpesa]);

        return $b->fresh();
    }

    private function url(Queue $q): string
    {
        return '/api/v1/auth/queues/'.$q->id.'/end';
    }

    #[Test]
    public function the_office_ends_a_trip_nobody_is_waiting_on_and_is_recorded_as_ending_it(): void
    {
        $trip = $this->trip();
        $admin = $this->admin($trip);

        Sanctum::actingAs($admin);
        $this->postJson($this->url($trip['queue']))->assertOk()
            ->assertJsonPath('trip.status', 'Completed')
            ->assertJsonPath('trip.ended_by', $admin->id);

        $q = $trip['queue']->fresh();
        $this->assertSame('Completed', $q->queue_status->status);
        $this->assertNotNull($q->end_time);
        $this->assertSame($admin->id, (int) $q->ended_by);
    }

    #[Test]
    public function the_office_never_decides_who_boarded(): void
    {
        $trip = $this->trip();
        $waiting = $this->paid($trip);

        Sanctum::actingAs($this->admin($trip));
        foreach ([[], ['unmarked' => 'no_show']] as $body) {
            $this->postJson($this->url($trip['queue']), $body)->assertStatus(409)
                ->assertJsonPath('unmarked.0.id', $waiting->id);
        }

        $this->assertSame('Active', $trip['queue']->fresh()->queue_status->status, 'the trip goes on');
        $this->assertTrue((bool) $waiting->fresh()->status, 'the passenger is neither cancelled nor refunded');

        // Once the crew has marked them, the office can end it.
        $waiting->update(['boarded' => true]);
        $this->postJson($this->url($trip['queue']))->assertOk();
        $this->assertTrue((bool) $waiting->fresh()->status, 'they rode; nothing to refund');
    }

    #[Test]
    public function only_a_trip_that_is_running_can_be_ended(): void
    {
        $waitingInLine = $this->trip('Pending', Queue::KIND_STAGE);
        Sanctum::actingAs($this->admin($waitingInLine));
        $this->postJson($this->url($waitingInLine['queue']))->assertStatus(409);
        $this->assertSame('Pending', $waitingInLine['queue']->fresh()->queue_status->status);

        $done = $this->trip();
        Sanctum::actingAs($this->admin($done));
        $this->postJson($this->url($done['queue']))->assertOk();
        $this->postJson($this->url($done['queue']))->assertStatus(409)->assertJsonPath('error', 'This trip has already ended.');
    }

    #[Test]
    public function another_saccos_trip_is_not_found_and_viewing_is_not_ending(): void
    {
        $trip = $this->trip();
        $outsider = $this->makeUser(['Edit Queues', 'View Queues'], $this->makeSacco());

        Sanctum::actingAs($outsider);
        $this->postJson($this->url($trip['queue']))->assertNotFound();

        Sanctum::actingAs($this->makeUser(['View Queues'], $trip['world']['sacco']));
        $this->postJson($this->url($trip['queue']))->assertForbidden();

        $this->assertSame('Active', $trip['queue']->fresh()->queue_status->status);
    }

    #[Test]
    public function the_driver_ending_it_is_recorded_too(): void
    {
        $trip = $this->trip();
        $driver = $this->makeUser([], $trip['world']['sacco']);
        VehicleUser::create([
            'user_id' => $driver->id, 'vehicle_id' => $trip['world']['vehicle']->id,
            'sacco_id' => $trip['world']['sacco']->id, 'status' => true, 'start_date' => now(),
        ]);

        Sanctum::actingAs($driver);
        $this->postJson('/api/v1/auth/driver/trip/end')->assertOk();

        $this->assertSame($driver->id, (int) $trip['queue']->fresh()->ended_by);
    }
}
