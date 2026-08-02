<?php

declare(strict_types=1);

namespace Tests\Feature\Queues;

use App\Enums\PaymentMethod;
use App\Models\Booking;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyProgram;
use App\Models\LoyaltyTransaction;
use App\Models\Sacco;
use App\Models\User;
use App\Services\Loyalty\LoyaltyService;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;

/**
 * Loyalty points: event-driven earning on a paid ride + redeeming a free ride.
 * - App\Services\Loyalty\LoyaltyService
 * - EarnLoyaltyPoints listener on BookingPaid (fired by the Booking observer)
 * - LoyaltyController (summary / redeem)
 */
final class LoyaltyTest extends QueueTestCase
{
    private function program(Sacco $sacco, float $divisor = 100, float $threshold = 500): LoyaltyProgram
    {
        return LoyaltyProgram::create([
            'sacco_id' => $sacco->id,
            'divisor' => $divisor,
            'redemption_threshold' => $threshold,
            'is_active' => true,
        ]);
    }

    private function giveBalance(User $user, Sacco $sacco, float $balance): void
    {
        LoyaltyAccount::withoutGlobalScopes()->create([
            'user_id' => $user->id, 'sacco_id' => $sacco->id, 'balance' => $balance,
        ]);
    }

    private function payableBooking(array $world, User $user): Booking
    {
        $pending = $this->makeQueueStatus('Pending', 'Pending');
        $queue = $this->makeQueue($world['vehicle'], $world['terminus'], $world['route'], $pending, $world['owner']);

        return Booking::create([
            'name' => 'Wanjiku', 'phone' => '254700111222', 'passengers' => 1,
            'user_id' => $user->id, 'queue_id' => $queue->id,
            'from_id' => $world['from']->id, 'to_id' => $world['to']->id,
            'amount' => 200, 'created_by' => $user->id,
        ]);
    }

    #[Test]
    public function a_paid_ride_earns_points_proportional_to_the_fare(): void
    {
        $world = $this->makeWorld();
        $this->program($world['sacco'], divisor: 100);      // 1 point per KES 100
        $user = $this->makeUser([], $world['sacco']);
        $booking = $this->payableBooking($world, $user);     // fare 200

        $booking->paid = true;
        $booking->save();                                    // fires BookingPaid → earn

        $this->assertEquals(2.0, (float) LoyaltyAccount::withoutGlobalScopes()
            ->where('user_id', $user->id)->where('sacco_id', $world['sacco']->id)->value('balance'));
        $this->assertSame(1, LoyaltyTransaction::withoutGlobalScopes()
            ->where('booking_id', $booking->id)->where('type', 'earned')->count());
    }

    #[Test]
    public function earning_is_idempotent(): void
    {
        $world = $this->makeWorld();
        $this->program($world['sacco']);
        $user = $this->makeUser([], $world['sacco']);
        $booking = $this->payableBooking($world, $user);

        $booking->paid = true;
        $booking->save();
        $booking->touch();          // save again — must not double-credit
        $booking->save();

        $this->assertEquals(2.0, (float) LoyaltyAccount::withoutGlobalScopes()
            ->where('user_id', $user->id)->value('balance'));
        $this->assertSame(1, LoyaltyTransaction::withoutGlobalScopes()->where('type', 'earned')->count());
    }

    #[Test]
    public function earning_survives_being_settled_inside_a_transaction(): void
    {
        // The reconcile path flips paid INSIDE a DB transaction; the earn runs as a
        // savepoint and must still commit along with the settlement.
        $world = $this->makeWorld();
        $this->program($world['sacco'], divisor: 100);
        $user = $this->makeUser([], $world['sacco']);
        $booking = $this->payableBooking($world, $user);

        DB::transaction(function () use ($booking) {
            $booking->paid = true;
            $booking->save();
        });

        $this->assertEquals(2.0, (float) LoyaltyAccount::withoutGlobalScopes()
            ->where('user_id', $user->id)->value('balance'));
    }

    #[Test]
    public function a_failing_earner_never_rolls_back_the_payment(): void
    {
        // A loyalty service that always throws — the payment must still commit, and
        // the failure must be swallowed rather than propagate to the settlement.
        $this->app->instance(LoyaltyService::class, new class extends LoyaltyService
        {
            public function earnForBooking(Booking $booking): ?LoyaltyTransaction
            {
                throw new \RuntimeException('loyalty exploded');
            }
        });

        $world = $this->makeWorld();
        $this->program($world['sacco']);
        $user = $this->makeUser([], $world['sacco']);
        $booking = $this->payableBooking($world, $user);

        DB::transaction(function () use ($booking) {
            $booking->paid = true;
            $booking->save();   // fires the throwing earner — must not roll this back
        });

        $this->assertTrue((bool) Booking::withoutGlobalScopes()->find($booking->id)->paid,
            'A failing loyalty earner must never roll back the payment.');
        $this->assertSame(0, LoyaltyTransaction::withoutGlobalScopes()->where('type', 'earned')->count());
    }

    #[Test]
    public function no_points_are_earned_without_an_active_program(): void
    {
        $world = $this->makeWorld();          // no loyalty program
        $user = $this->makeUser([], $world['sacco']);
        $booking = $this->payableBooking($world, $user);

        $booking->paid = true;
        $booking->save();

        $this->assertSame(0, LoyaltyAccount::withoutGlobalScopes()->count());
    }

    #[Test]
    public function points_redeem_a_free_ride_and_settle_the_booking(): void
    {
        $world = $this->makeWorld();
        $this->program($world['sacco'], divisor: 100, threshold: 500);
        $user = $this->makeUser([], $world['sacco']);
        $this->giveBalance($user, $world['sacco'], 600);
        $booking = $this->payableBooking($world, $user);     // reserved, unpaid

        Sanctum::actingAs($user);
        $this->postJson('/api/auth/book_a_ride/loyalty/redeem', ['booking_id' => $booking->id])
            ->assertOk()
            ->assertJsonPath('points_spent', 500);

        $booking->refresh();
        $this->assertTrue((bool) $booking->paid);
        $this->assertSame(PaymentMethod::LoyaltyPoints, $booking->payment_method);
        // 600 − 500 spent, and the free ride did NOT itself earn.
        $this->assertEquals(100.0, (float) LoyaltyAccount::withoutGlobalScopes()
            ->where('user_id', $user->id)->value('balance'));
        $this->assertSame(0, LoyaltyTransaction::withoutGlobalScopes()->where('type', 'earned')->count());
    }

    #[Test]
    public function redeeming_without_enough_points_is_refused(): void
    {
        $world = $this->makeWorld();
        $this->program($world['sacco'], threshold: 500);
        $user = $this->makeUser([], $world['sacco']);
        $this->giveBalance($user, $world['sacco'], 100);     // short
        $booking = $this->payableBooking($world, $user);

        Sanctum::actingAs($user);
        $this->postJson('/api/auth/book_a_ride/loyalty/redeem', ['booking_id' => $booking->id])
            ->assertStatus(422);

        $this->assertFalse((bool) $booking->fresh()->paid);
    }

    #[Test]
    public function a_passenger_cannot_redeem_someone_elses_booking(): void
    {
        $world = $this->makeWorld();
        $this->program($world['sacco'], threshold: 500);
        $owner = $this->makeUser([], $world['sacco']);
        $this->giveBalance($owner, $world['sacco'], 600);
        $booking = $this->payableBooking($world, $owner);

        Sanctum::actingAs($this->makeUser([], $world['sacco'])); // a different user
        $this->postJson('/api/auth/book_a_ride/loyalty/redeem', ['booking_id' => $booking->id])
            ->assertStatus(403);
    }

    #[Test]
    public function the_summary_lists_per_sacco_cards(): void
    {
        $world = $this->makeWorld();
        $this->program($world['sacco'], threshold: 500);
        $user = $this->makeUser([], $world['sacco']);
        $this->giveBalance($user, $world['sacco'], 620);

        Sanctum::actingAs($user);
        $this->getJson('/api/auth/book_a_ride/loyalty/summary')
            ->assertOk()
            ->assertJsonCount(1, 'loyalty')
            ->assertJsonPath('loyalty.0.balance', 620)
            ->assertJsonPath('loyalty.0.eligible_to_redeem', true)
            ->assertJsonPath('loyalty.0.points_to_reward', 0);
    }

    #[Test]
    public function a_sacco_configures_its_loyalty_program(): void
    {
        $world = $this->makeWorld();
        $admin = $this->makeUser(['Edit Loyalty'], $world['sacco']); // route now enforces this permission
        Sanctum::actingAs($admin);

        $this->postJson('/api/auth/saccos/loyalty/save', [
            'sacco_id' => $world['sacco']->id,
            'divisor' => 100,
            'redemption_threshold' => 500,
        ])->assertOk()->assertJsonPath('program.redemption_threshold', 500);

        $this->assertDatabaseHas('loyalty_programs', [
            'sacco_id' => $world['sacco']->id, 'divisor' => 100, 'redemption_threshold' => 500,
        ]);
    }
}
