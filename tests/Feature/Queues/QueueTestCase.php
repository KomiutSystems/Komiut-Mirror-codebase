<?php

declare(strict_types=1);

namespace Tests\Feature\Queues;

use App\Models\Booking;
use App\Models\Gender;
use App\Models\Place;
use App\Models\Queue;
use App\Models\QueueStatus;
use App\Models\Route;
use App\Models\RouteStage;
use App\Models\Sacco;
use App\Models\Seat;
use App\Models\SeatArrangement;
use App\Models\SeatBooking;
use App\Models\Terminus;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Shared scaffolding for the queue / booking regression tests.
 *
 * Deliberately does NOT use database factories: the core model factories
 * (User, Sacco, Vehicle, ...) are owned elsewhere, so every row is built
 * here with explicit attributes to keep this suite self-contained.
 */
abstract class QueueTestCase extends TestCase
{
    use RefreshDatabase;

    protected int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        // SendFCMJob / SendSMSJob would otherwise run inline (QUEUE_CONNECTION=sync).
        Bus::fake();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    protected function nextSequence(): int
    {
        return ++$this->sequence;
    }

    protected function makeSacco(bool $active = true): Sacco
    {
        $n = $this->nextSequence();

        return Sacco::create([
            'name' => "Sacco {$n}",
            'slogan' => 'On time, every time',
            'phone' => '0700000' . str_pad((string) $n, 3, '0', STR_PAD_LEFT),
            'status' => $active ? 1 : 0,
        ]);
    }

    /**
     * @param  array<int, string>  $permissions
     */
    protected function makeUser(array $permissions = [], ?Sacco $sacco = null, bool $status = true): User
    {
        $n = $this->nextSequence();

        $gender = Gender::firstOrCreate(['name' => 'Male'], ['status' => true]);

        $user = User::create([
            'firstname' => "Test{$n}",
            'lastname' => 'User',
            'email' => "user{$n}@example.test",
            'phone' => '2547' . str_pad((string) $n, 8, '0', STR_PAD_LEFT),
            'password' => 'password',
            'dob' => '1990-01-01',
            'gender_id' => $gender->id,
            'sacco_id' => $sacco?->id,
            'status' => $status,
        ]);

        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        if ($permissions !== []) {
            $user->givePermissionTo($permissions);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user;
    }

    protected function makePlace(string $name): Place
    {
        return Place::create([
            'name' => $name,
            'county_name' => 'Nairobi',
            'longitude' => 36.8,
            'latitude' => -1.28,
            'status' => true,
        ]);
    }

    protected function makeSeat(int $seats = 4): Seat
    {
        $n = $this->nextSequence();

        return Seat::create([
            'name' => "Layout {$n}",
            'seats' => $seats,
            'rows' => $seats,
            'columns' => 1,
            'status' => true,
        ]);
    }

    /**
     * @return array<int, SeatArrangement>
     */
    protected function makeSeatArrangements(Seat $seat, int $count = 4): array
    {
        $arrangements = [];
        for ($i = 1; $i <= $count; $i++) {
            $arrangements[] = SeatArrangement::create([
                'name' => "S{$i}",
                'row' => $i,
                'column' => 1,
                'seat_id' => $seat->id,
                'status' => true,
            ]);
        }

        return $arrangements;
    }

    protected function makeVehicle(Sacco $sacco, User $owner, Seat $seat): Vehicle
    {
        $n = $this->nextSequence();

        return Vehicle::create([
            'plate' => 'KDA' . str_pad((string) $n, 3, '0', STR_PAD_LEFT) . 'X',
            'fleet_no' => (string) $n,
            'sacco_id' => $sacco->id,
            'user_id' => $owner->id,
            'seat_id' => $seat->id,
            'status' => true,
        ]);
    }

    protected function makeRoute(Place $from, Place $to): Route
    {
        return Route::create([
            'name' => $from->name . ' - ' . $to->name,
            'from_id' => $from->id,
            'to_id' => $to->id,
            'status' => 1,
        ]);
    }

    protected function makeRouteStage(Route $route, Place $place, float $distance): RouteStage
    {
        return RouteStage::create([
            'route_id' => $route->id,
            'place_id' => $place->id,
            'longitude' => 36.8,
            'latitude' => -1.28,
            'distance' => $distance,
            'status' => true,
        ]);
    }

    protected function makeTerminus(Place $place): Terminus
    {
        $n = $this->nextSequence();

        return Terminus::create([
            'name' => "Terminus {$n}",
            'longitude' => 36.8,
            'latitude' => -1.28,
            'place_id' => $place->id,
            'status' => true,
        ]);
    }

    protected function makeQueueStatus(string $name, string $status): QueueStatus
    {
        return QueueStatus::create([
            'name' => $name,
            'status' => $status,
            'active' => true,
        ]);
    }

    protected function makeQueue(
        Vehicle $vehicle,
        Terminus $terminus,
        Route $route,
        QueueStatus $status,
        User $user,
        string $queueNumber = 'QN-1'
    ): Queue {
        return Queue::create([
            'queue_number' => $queueNumber,
            'vehicle_id' => $vehicle->id,
            'terminus_id' => $terminus->id,
            'queue_status_id' => $status->id,
            'route_id' => $route->id,
            'user_id' => $user->id,
            'amount' => 200,
            'start_time' => now(),
            'queue_type' => false,
        ]);
    }

    protected function makeBooking(Queue $queue, User $user, Place $from, Place $to, string $name = 'Passenger'): Booking
    {
        return Booking::create([
            'name' => $name,
            'phone' => '254700111222',
            'passengers' => 1,
            'user_id' => $user->id,
            'queue_id' => $queue->id,
            'from_id' => $from->id,
            'to_id' => $to->id,
            'amount' => 200,
            'created_by' => $user->id,
        ]);
    }

    protected function makeSeatBooking(Booking $booking, SeatArrangement $arrangement): SeatBooking
    {
        return SeatBooking::create([
            'seat_id' => $arrangement->id,
            'booking_id' => $booking->id,
        ]);
    }

    /**
     * Builds the full graph needed to queue a vehicle:
     * place(from) -> terminus, route(from -> to) with two stages, vehicle + seats.
     *
     * @return array{
     *     sacco: Sacco, owner: User, from: Place, to: Place, route: Route,
     *     terminus: Terminus, vehicle: Vehicle, seat: Seat,
     *     arrangements: array<int, SeatArrangement>,
     *     stages: array<int, RouteStage>
     * }
     */
    protected function makeWorld(): array
    {
        $sacco = $this->makeSacco();
        $owner = $this->makeUser([], $sacco);
        $from = $this->makePlace('Nairobi CBD ' . $this->nextSequence());
        $to = $this->makePlace('Thika ' . $this->nextSequence());
        $route = $this->makeRoute($from, $to);
        $stages = [
            $this->makeRouteStage($route, $from, 0),
            $this->makeRouteStage($route, $to, 40),
        ];
        $terminus = $this->makeTerminus($from);
        $seat = $this->makeSeat();
        $arrangements = $this->makeSeatArrangements($seat);
        $vehicle = $this->makeVehicle($sacco, $owner, $seat);

        return compact('sacco', 'owner', 'from', 'to', 'route', 'terminus', 'vehicle', 'seat', 'arrangements', 'stages');
    }
}
