<?php

declare(strict_types=1);

namespace Tests\Feature\Sacco;

use App\Models\Booking;
use App\Models\Vehicle;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Queues\QueueTestCase;

/**
 * Suspending and restoring a SACCO's resources, and the booking vocabulary.
 *
 * The API had one delete route in total, so "take this bus off the road" meant
 * re-submitting the whole vehicle through its add endpoint with status = 0.
 */
final class ResourceStateTest extends QueueTestCase
{
    private const ENDPOINT = '/api/v1/auth/sacco';

    #[Test]
    public function a_vehicle_can_be_suspended_and_restored(): void
    {
        $sacco = $this->makeSacco();
        $admin = $this->makeUser(['Edit Vehicles'], $sacco);
        $vehicle = $this->makeVehicle($sacco, $admin, $this->makeSeat());

        Sanctum::actingAs($admin);

        $this->postJson(self::ENDPOINT."/vehicles/{$vehicle->id}/state", ['suspend' => true])
            ->assertOk()
            ->assertJsonPath('status', false);
        $this->assertFalse((bool) $vehicle->fresh()->status);

        $this->postJson(self::ENDPOINT."/vehicles/{$vehicle->id}/state", ['suspend' => false])
            ->assertOk()
            ->assertJsonPath('status', true);
        $this->assertTrue((bool) $vehicle->fresh()->status);
    }

    #[Test]
    public function suspending_never_deletes_the_row_or_its_money(): void
    {
        // Suspension rather than deletion is the whole point: a vehicle is
        // referenced by transactions and summaries, so a hard delete either
        // fails on a foreign key or orphans money.
        $sacco = $this->makeSacco();
        $admin = $this->makeUser(['Edit Vehicles'], $sacco);
        $vehicle = $this->makeVehicle($sacco, $admin, $this->makeSeat());

        Sanctum::actingAs($admin);
        $this->postJson(self::ENDPOINT."/vehicles/{$vehicle->id}/state", ['suspend' => true])->assertOk();

        $this->assertDatabaseHas('vehicles', ['id' => $vehicle->id]);
    }

    #[Test]
    public function it_refuses_without_the_permission(): void
    {
        $sacco = $this->makeSacco();
        $vehicle = $this->makeVehicle($sacco, $this->makeUser([], $sacco), $this->makeSeat());

        Sanctum::actingAs($this->makeUser([], $sacco));   // no Edit Vehicles

        $this->postJson(self::ENDPOINT."/vehicles/{$vehicle->id}/state", ['suspend' => true])
            ->assertStatus(403);

        $this->assertTrue((bool) $vehicle->fresh()->status, 'A refused request must change nothing.');
    }

    #[Test]
    public function one_sacco_cannot_suspend_anothers_vehicle(): void
    {
        // SaccoScope is the tenant boundary here — find() honouring it is what
        // stops a SACCO admin reaching across.
        $mine = $this->makeSacco();
        $theirs = $this->makeSacco();
        $victim = $this->makeVehicle($theirs, $this->makeUser([], $theirs), $this->makeSeat());

        Sanctum::actingAs($this->makeUser(['Edit Vehicles'], $mine));

        $this->postJson(self::ENDPOINT."/vehicles/{$victim->id}/state", ['suspend' => true])
            ->assertStatus(404);

        $this->assertTrue((bool) $victim->fresh()->status);
    }

    #[Test]
    public function an_admin_cannot_suspend_their_own_account(): void
    {
        // It would lock them out of the dashboard they need to undo it.
        $sacco = $this->makeSacco();
        $admin = $this->makeUser(['Edit Sacco Members'], $sacco);

        Sanctum::actingAs($admin);

        $this->postJson(self::ENDPOINT."/members/{$admin->id}/state", ['suspend' => true])
            ->assertStatus(422);

        $this->assertTrue((bool) $admin->fresh()->status);
    }

    #[Test]
    public function an_unknown_resource_is_refused(): void
    {
        Sanctum::actingAs($this->makeUser(['Edit Vehicles'], $this->makeSacco()));

        $this->postJson(self::ENDPOINT.'/saccos/1/state', ['suspend' => true])
            ->assertStatus(404);
    }

    #[Test]
    public function a_cancelled_booking_is_reported_as_failed(): void
    {
        // CheckPassengerPayments cancels anything still unpaid after two minutes
        // by setting status = 0 and releasing the seat. That is a FAILED
        // booking, and until now it had no name — every listing mixed those in
        // with live ones.
        $world = $this->makeWorld();
        $pending = $this->makeQueueStatus('Pending', 'Pending');
        $queue = $this->makeQueue($world['vehicle'], $world['terminus'], $world['route'], $pending, $world['owner']);
        $passenger = $this->makeUser([], $world['sacco']);

        $reserved = $this->makeBooking($queue, $passenger, $world['from'], $world['to'], 'Wanjiku');
        $failed = $this->makeBooking($queue, $passenger, $world['from'], $world['to'], 'Otieno');
        $failed->update(['status' => false]);
        $paid = $this->makeBooking($queue, $passenger, $world['from'], $world['to'], 'Achieng');
        $paid->update(['paid' => true]);

        $this->assertSame('reserved', $reserved->fresh()->status_label);
        $this->assertSame('failed', $failed->fresh()->status_label);
        $this->assertSame('confirmed', $paid->fresh()->status_label);

        // And the scope both the driver app and the dashboard filter through.
        $this->assertSame(1, Booking::where('queue_id', $queue->id)->statusIs('failed')->count());
        $this->assertSame(1, Booking::where('queue_id', $queue->id)->statusIs('reserved')->count());
        $this->assertSame(1, Booking::where('queue_id', $queue->id)->statusIs('confirmed')->count());
        // No filter shows everything, cancelled included, rather than hiding it.
        $this->assertSame(3, Booking::where('queue_id', $queue->id)->statusIs(null)->count());
    }

    #[Test]
    public function boarded_outranks_paid(): void
    {
        // A boarded booking is also paid; the label must report the most
        // advanced state, not the first flag that happens to be true.
        $world = $this->makeWorld();
        $pending = $this->makeQueueStatus('Pending', 'Pending');
        $queue = $this->makeQueue($world['vehicle'], $world['terminus'], $world['route'], $pending, $world['owner']);
        $b = $this->makeBooking($queue, $this->makeUser([], $world['sacco']), $world['from'], $world['to'], 'Kip');
        $b->update(['paid' => true, 'boarded' => true]);

        $this->assertSame('boarded', $b->fresh()->status_label);
    }
}
