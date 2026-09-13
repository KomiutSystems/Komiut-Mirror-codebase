<?php

declare(strict_types=1);

namespace Tests\Feature\Booking;

use App\Models\Booking;
use App\Models\ExpenseFee;
use App\Models\Queue;
use App\Models\User;
use App\Models\VehicleExpenseAndFee;
use App\Models\VehicleLocation;
use App\Models\VehicleUser;
use App\Services\Location\VehicleLocationService;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Queues\QueueTestCase;

/**
 * The four things the backend owes a phone on a matatu route, from the mobile
 * review of 2026-09-13:
 *
 *  - a retried POST must not write twice (Idempotency-Key);
 *  - a heartbeat re-sending a dead GPS fix must not paint the bus as live
 *    (fixed_at decides freshness, not arrival time);
 *  - a group booking is a passenger COUNT, never "whatever seat the app could
 *    find" (passengers, seats optional);
 *  - the STK push says what is being paid, from the server's record.
 */
final class RetriesAreSafeTest extends QueueTestCase
{
    private const ADD = '/api/v1/auth/book_a_ride/booking/add';

    private function queue(array $world): Queue
    {
        $q = $this->makeQueue(
            $world['vehicle'], $world['terminus'], $world['route'],
            $this->makeQueueStatus('Pending '.$this->nextSequence(), 'Pending'), $world['owner'],
        );
        $this->pingFrom($world['vehicle'], $q);

        return $q;
    }

    private function crew(array $world): User
    {
        $d = $this->makeUser([], $world['sacco']);
        VehicleUser::create([
            'user_id' => $d->id, 'vehicle_id' => $world['vehicle']->id,
            'sacco_id' => $world['sacco']->id, 'status' => true, 'start_date' => now(),
        ]);

        return $d;
    }

    // ------------------------------------------------------------ idempotency

    #[Test]
    public function the_same_booking_attempt_sent_twice_creates_one_booking_and_replays_the_answer(): void
    {
        $world = $this->makeWorld();
        $queue = $this->queue($world);
        Sanctum::actingAs($this->makeUser());
        $payload = ['id' => $queue->id, 'passengers' => 2, 'name' => 'Wanjiku', 'phone' => '0722123456'];
        $headers = ['Idempotency-Key' => 'tap-7f3a'];

        $first = $this->withHeaders($headers)->postJson(self::ADD, $payload)->assertOk();
        $first->assertHeader('Idempotent-Replayed', 'false');

        // The lost-response retry: identical key, identical answer, no second row.
        $second = $this->withHeaders($headers)->postJson(self::ADD, $payload)->assertOk();
        $second->assertHeader('Idempotent-Replayed', 'true');
        $this->assertSame($first->json(), $second->json());
        $this->assertSame(1, Booking::withoutGlobalScopes()->count());

        // A new tap is a new key, and a new booking.
        $this->withHeaders(['Idempotency-Key' => 'tap-8b21'])->postJson(self::ADD, $payload)->assertOk();
        $this->assertSame(2, Booking::withoutGlobalScopes()->count());
    }

    #[Test]
    public function a_key_belongs_to_the_user_who_sent_it(): void
    {
        // Another passenger sending the same key must not be handed the first
        // passenger's booking back.
        $world = $this->makeWorld();
        $queue = $this->queue($world);
        $payload = ['id' => $queue->id, 'passengers' => 1, 'name' => 'A', 'phone' => '0722123456'];

        Sanctum::actingAs($this->makeUser());
        $mine = $this->withHeaders(['Idempotency-Key' => 'same-key'])->postJson(self::ADD, $payload)->assertOk()->json('booking_id');

        Sanctum::actingAs($this->makeUser());
        $theirs = $this->withHeaders(['Idempotency-Key' => 'same-key'])->postJson(self::ADD, $payload)->assertOk()->json('booking_id');

        $this->assertNotSame($mine, $theirs);
        $this->assertSame(2, Booking::withoutGlobalScopes()->count());
    }

    #[Test]
    public function a_retried_expense_is_recorded_once(): void
    {
        $world = $this->makeWorld();
        $fuel = ExpenseFee::create(['name' => 'Fuel', 'status' => true]);
        Sanctum::actingAs($this->crew($world));
        $headers = ['Idempotency-Key' => 'fuel-0630'];

        $this->withHeaders($headers)->postJson('/api/v1/auth/driver/expenses', ['expense_fee_id' => $fuel->id, 'amount' => 2500])->assertCreated();
        $this->withHeaders($headers)->postJson('/api/v1/auth/driver/expenses', ['expense_fee_id' => $fuel->id, 'amount' => 2500])
            ->assertCreated()->assertHeader('Idempotent-Replayed', 'true');

        $this->assertSame(1, VehicleExpenseAndFee::where('vehicle_id', $world['vehicle']->id)->count(), 'KES 2,500 of fuel, once');
    }

    #[Test]
    public function without_a_key_nothing_changes(): void
    {
        $world = $this->makeWorld();
        $queue = $this->queue($world);
        Sanctum::actingAs($this->makeUser());
        $payload = ['id' => $queue->id, 'passengers' => 1, 'name' => 'A', 'phone' => '0722123456'];

        $this->postJson(self::ADD, $payload)->assertOk()->assertHeaderMissing('Idempotent-Replayed');
        $this->postJson(self::ADD, $payload)->assertOk();
        $this->assertSame(2, Booking::withoutGlobalScopes()->count(), 'no key, no dedup -- as before');
    }

    // ----------------------------------------------------------- dead GPS fix

    #[Test]
    public function a_heartbeat_re_sending_an_old_fix_is_stored_but_not_live(): void
    {
        $this->makeQueueStatus('Active', 'Active');
        $world = $this->makeWorld();
        Sanctum::actingAs($this->crew($world));

        // The phone lost GPS an hour ago and keeps sending the last fix it had.
        $stale = now()->subHour();
        $this->postJson('/api/v1/auth/book_a_ride/location', [
            'latitude' => -1.20, 'longitude' => 36.93, 'route_id' => $world['route']->id,
            'fixed_at' => $stale->toIso8601String(),
        ])->assertStatus(202)->assertJsonPath('live', false);

        $row = VehicleLocation::where('vehicle_id', $world['vehicle']->id)->first();
        $this->assertTrue($row->recorded_at->lte($stale->addSecond()), 'recorded when the fix was taken, not when it arrived');
        $this->assertTrue((bool) $row->broadcasting);

        // Not on offer to passengers: the live window is judged from the fix.
        Sanctum::actingAs($this->makeUser());
        $this->getJson('/api/v1/auth/book_a_ride/queues?from_id='.$world['from']->id.'&to_id='.$world['to']->id)
            ->assertOk()->assertJsonCount(0, 'queues');

        // A fresh fix brings it back.
        Sanctum::actingAs($this->crew($world));
        $this->postJson('/api/v1/auth/book_a_ride/location', [
            'latitude' => -1.19, 'longitude' => 36.94, 'route_id' => $world['route']->id,
            'fixed_at' => now()->subSeconds(3)->toIso8601String(),
        ])->assertStatus(202)->assertJsonPath('live', true);
    }

    #[Test]
    public function a_fix_from_the_future_is_clamped_to_now(): void
    {
        // A phone with a wrong clock must not be "live" for an hour after it stops.
        $world = $this->makeWorld();
        Sanctum::actingAs($this->crew($world));

        $this->postJson('/api/v1/auth/book_a_ride/location', [
            'latitude' => -1.20, 'longitude' => 36.93, 'fixed_at' => now()->addHour()->toIso8601String(),
        ])->assertStatus(202);

        $row = VehicleLocation::where('vehicle_id', $world['vehicle']->id)->first();
        $this->assertLessThanOrEqual(2, abs(now()->diffInSeconds($row->recorded_at, true)));
    }

    // ------------------------------------------------------- passenger count

    #[Test]
    public function a_group_booking_is_a_passenger_count_and_the_server_labels_the_seats(): void
    {
        $world = $this->makeWorld(); // KES 200 a seat, four seats on the map
        $queue = $this->queue($world);
        Sanctum::actingAs($this->makeUser());

        $r = $this->postJson(self::ADD, ['id' => $queue->id, 'passengers' => 3, 'name' => 'Wanjiku', 'phone' => '0722123456'])
            ->assertOk()
            ->assertJsonPath('passengers', 3)
            ->assertJsonPath('amount', 600)
            ->assertJsonPath('fare_per_seat', 200);

        $booking = Booking::withoutGlobalScopes()->findOrFail($r->json('booking_id'));
        $this->assertSame(3, (int) $booking->passengers);
        $this->assertSame(3, $booking->seats()->count(), 'three labels from the map, not one fallback seat');

        // Six is refused; the cap is the same either way in.
        $this->postJson(self::ADD, ['id' => $queue->id, 'passengers' => 6, 'name' => 'W', 'phone' => '0722123456'])
            ->assertStatus(400)->assertJsonStructure(['errors' => ['passengers']]);
    }

    #[Test]
    public function a_bus_with_no_seat_map_still_takes_a_group_as_a_count(): void
    {
        $world = $this->makeWorld();
        $world['vehicle']->forceFill(['seat_id' => null])->save();
        $queue = $this->queue($world);
        Sanctum::actingAs($this->makeUser());

        $r = $this->postJson(self::ADD, ['id' => $queue->id, 'passengers' => 2, 'name' => 'W', 'phone' => '0722123456'])
            ->assertOk()->assertJsonPath('passengers', 2)->assertJsonPath('amount', 400);

        $booking = Booking::withoutGlobalScopes()->findOrFail($r->json('booking_id'));
        $this->assertSame(2, (int) $booking->passengers);
        $this->assertSame(0, $booking->seats()->count(), 'no map, no labels -- like a cash boarding');
    }

    #[Test]
    public function neither_seats_nor_passengers_is_refused_before_anything_is_written(): void
    {
        $world = $this->makeWorld();
        $queue = $this->queue($world);
        Sanctum::actingAs($this->makeUser());

        $this->postJson(self::ADD, ['id' => $queue->id, 'name' => 'W', 'phone' => '0722123456'])
            ->assertStatus(400)->assertJsonStructure(['errors' => ['passengers', 'seats']]);
        $this->assertSame(0, Booking::withoutGlobalScopes()->count());
    }

    // ------------------------------------------------- what is being paid

    #[Test]
    public function the_stk_push_says_what_the_pin_is_for(): void
    {
        Http::fake([
            'api.safaricom.co.ke/oauth/v1/generate*' => Http::response(['access_token' => 'tok', 'expires_in' => '3599']),
            'api.safaricom.co.ke/mpesa/stkpush/v1/processrequest' => Http::response([
                'MerchantRequestID' => 'm-1', 'CheckoutRequestID' => 'ws_CO_1', 'ResponseCode' => '0',
                'ResponseDescription' => 'Success', 'CustomerMessage' => 'Success',
            ]),
        ]);
        $world = $this->makeWorld();
        \App\Models\MpesaPaymentSetting::create([
            'sacco_id' => $world['sacco']->id, 'consumer_key' => 'ck', 'consumer_secret' => 'cs', 'pass_key' => 'pk',
            'business_short_code' => '7071220', 'payment_mode' => 'CustomerBuyGoodsOnline', 'is_live' => true, 'status' => true,
        ]);
        $queue = $this->queue($world);
        $tom = $this->makeUser();
        Sanctum::actingAs($tom);
        $bookingId = $this->postJson(self::ADD, ['id' => $queue->id, 'passengers' => 2, 'name' => 'Tom', 'phone' => '0722123456'])
            ->assertOk()->json('booking_id');

        $push = $this->postJson('/api/v1/auth/mpesa/stk', ['phone' => '0722123456', 'booking_id' => $bookingId])->assertOk();

        $push->assertJsonPath('CheckoutRequestID', 'ws_CO_1')
            ->assertJsonPath('booking.id', $bookingId)
            ->assertJsonPath('booking.amount', 400)
            ->assertJsonPath('booking.passengers', 2)
            ->assertJsonPath('booking.vehicle.plate', $world['vehicle']->plate)
            ->assertJsonPath('booking.from', $world['from']->name)
            ->assertJsonPath('booking.to', $world['to']->name);

        // The open-prompt replay carries it too, so a retry shows the same thing.
        $this->postJson('/api/v1/auth/mpesa/stk', ['phone' => '0722123456', 'booking_id' => $bookingId])
            ->assertOk()->assertJsonPath('replay', true)->assertJsonPath('booking.amount', 400);
    }
}
