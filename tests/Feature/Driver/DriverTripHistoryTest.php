<?php

declare(strict_types=1);

namespace Tests\Feature\Driver;

use App\Enums\UserType;
use App\Models\Booking;
use App\Models\Queue;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleUser;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Queues\QueueTestCase;

/**
 * Trip history: the caller's VEHICLE's past queues, newest first, each with its
 * status and its paid takings.
 *
 * The pins that matter are the two scoping ones. History is keyed on the vehicle
 * resolved from the caller's open assignment, never on the driver, so a driver
 * who rotated buses this morning sees this bus — and can never see another one:
 * another vehicle's trips are excluded even inside the same SACCO.
 */
final class DriverTripHistoryTest extends QueueTestCase
{
    /**
     * A driver crewed onto the world's vehicle.
     *
     * @param  array<string,mixed>  $world
     */
    private function crew(array $world): User
    {
        $driver = $this->makeUser([], $world['sacco']);
        $driver->forceFill(['type' => UserType::Driver])->save();

        VehicleUser::create([
            'user_id' => $driver->id,
            'vehicle_id' => $world['vehicle']->id,
            'sacco_id' => $world['sacco']->id,
            'status' => true,
            'start_date' => now(),
        ]);

        return $driver;
    }

    /** A queue on the given vehicle carrying the given lifecycle status. */
    private function queueWith(array $world, Vehicle $vehicle, string $status, string $number): Queue
    {
        return $this->makeQueue(
            $vehicle,
            $world['terminus'],
            $world['route'],
            $this->makeQueueStatus($status.' '.$this->nextSequence(), $status),
            $world['owner'],
            $number,
        );
    }

    /** A PAID fare on a queue — set quietly so the BookingPaid hook does not fire. */
    private function paidFare(array $world, Queue $queue, float $amount): Booking
    {
        $booking = $this->makeBooking($queue, $world['owner'], $world['from'], $world['to']);
        $booking->forceFill(['amount' => $amount, 'paid' => true])->saveQuietly();

        return $booking;
    }

    #[Test]
    public function it_lists_completed_and_cancelled_trips_with_their_paid_takings(): void
    {
        $world = $this->makeWorld();

        $completed = $this->queueWith($world, $world['vehicle'], 'Completed', 'QN-1');
        $this->paidFare($world, $completed, 200);
        $this->paidFare($world, $completed, 200);
        // An unpaid fare on the same queue must NOT count toward takings.
        $this->makeBooking($completed, $world['owner'], $world['from'], $world['to']);

        $cancelled = $this->queueWith($world, $world['vehicle'], 'Cancelled', 'QN-2');
        $this->paidFare($world, $cancelled, 150);

        Sanctum::actingAs($this->crew($world));

        $response = $this->getJson('/api/v1/auth/driver/trips')->assertOk();

        $trips = collect($response->json('trips'));
        $this->assertSame(2, $trips->count());

        $completedRow = $trips->firstWhere('id', $completed->id);
        $this->assertSame('Completed', $completedRow['status']);
        // Only the two PAID fares (200 + 200), not the unpaid one.
        $this->assertEquals(400.0, $completedRow['takings']);

        $cancelledRow = $trips->firstWhere('id', $cancelled->id);
        $this->assertSame('Cancelled', $cancelledRow['status']);
        $this->assertEquals(150.0, $cancelledRow['takings']);

        // The status vocabulary is exposed for the app's tabs.
        $response->assertJsonPath('statuses', ['Completed', 'Cancelled', 'Active', 'Pending']);
        $response->assertJsonStructure(['trips' => [['id', 'queue_number', 'position', 'route' => ['from', 'to'], 'status', 'start_time', 'end_time', 'takings']]]);
    }

    #[Test]
    public function another_vehicles_trips_are_excluded_even_in_the_same_sacco(): void
    {
        $world = $this->makeWorld();

        $mine = $this->queueWith($world, $world['vehicle'], 'Completed', 'QN-1');

        // A second bus in the SAME sacco. Its trip must never surface here — the
        // vehicle boundary, not the sacco, is what scopes history.
        $otherVehicle = $this->makeVehicle($world['sacco'], $world['owner'], $this->makeSeat());
        $theirs = $this->queueWith($world, $otherVehicle, 'Completed', 'QN-9');

        Sanctum::actingAs($this->crew($world));

        $ids = collect($this->getJson('/api/v1/auth/driver/trips')->assertOk()->json('trips'))->pluck('id');

        $this->assertTrue($ids->contains($mine->id));
        $this->assertFalse($ids->contains($theirs->id));
    }

    #[Test]
    public function the_list_is_paginated_at_twenty(): void
    {
        $world = $this->makeWorld();
        $status = $this->makeQueueStatus('Completed '.$this->nextSequence(), 'Completed');

        for ($i = 1; $i <= 25; $i++) {
            $this->makeQueue($world['vehicle'], $world['terminus'], $world['route'], $status, $world['owner'], 'QN-'.$i);
        }

        Sanctum::actingAs($this->crew($world));

        $first = $this->getJson('/api/v1/auth/driver/trips')->assertOk();
        $first->assertJsonPath('total', 25)
            ->assertJsonPath('per_page', 20)
            ->assertJsonPath('current_page', 1)
            ->assertJsonPath('last_page', 2);
        $this->assertCount(20, $first->json('trips'));

        $second = $this->getJson('/api/v1/auth/driver/trips?page=2')->assertOk();
        $second->assertJsonPath('current_page', 2);
        $this->assertCount(5, $second->json('trips'));
    }

    #[Test]
    public function a_status_filter_narrows_the_list(): void
    {
        $world = $this->makeWorld();

        $completed = $this->queueWith($world, $world['vehicle'], 'Completed', 'QN-1');
        $cancelled = $this->queueWith($world, $world['vehicle'], 'Cancelled', 'QN-2');

        Sanctum::actingAs($this->crew($world));

        $ids = collect($this->getJson('/api/v1/auth/driver/trips?status=cancelled')->assertOk()->json('trips'))
            ->pluck('id');

        $this->assertTrue($ids->contains($cancelled->id));
        $this->assertFalse($ids->contains($completed->id));
    }

    #[Test]
    public function an_unassigned_driver_is_refused(): void
    {
        $driver = $this->makeUser([], $this->makeSacco());
        $driver->forceFill(['type' => UserType::Driver])->save();

        Sanctum::actingAs($driver);

        $this->getJson('/api/v1/auth/driver/trips')->assertStatus(403);
    }

    #[Test]
    public function the_endpoint_needs_authentication(): void
    {
        $this->getJson('/api/v1/auth/driver/trips')->assertStatus(401);
    }
}
