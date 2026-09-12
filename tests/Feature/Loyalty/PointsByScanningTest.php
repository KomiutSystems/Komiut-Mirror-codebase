<?php

declare(strict_types=1);

namespace Tests\Feature\Loyalty;

use App\Enums\LoyaltyTransactionType;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyProgram;
use App\Models\LoyaltyTransaction;
use App\Models\QrcodePayment;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Queues\QueueTestCase;

/**
 * Paying with points by SCANNING THE BUS — no queue, no booking.
 *
 * Points are a payment method, and a payment method should not care whether the
 * vehicle happens to be sitting in a queue. Until now they did:
 * `bookings.queue_id` is NOT NULL and redeemForBooking derives the SACCO as
 * booking -> queue -> vehicle -> sacco_id, so a bus that had left the stage could
 * not be paid for with points at all — while the same passenger could always
 * M-Pesa its till.
 *
 * This path takes the SACCO straight off the VEHICLE, which is the only thing a
 * QR scan identifies, and writes a `qrcode_payments` receipt: the same artefact
 * an M-Pesa QR payment writes, so everything already reading that table keeps
 * working.
 *
 * The endpoint existed before and was DEAD. It spent from the legacy `points`
 * table — keyed on phone, hardcoded 50, and empty since the per-SACCO rewrite —
 * so it told every passenger "You do not have enough points to proceed!"
 * regardless of the balance on their card. Its sibling getVehicle read the same
 * dead table, so the scan screen and the payment disagreed about the same money.
 */
final class PointsByScanningTest extends QueueTestCase
{
    private const URL = '/api/auth/qrcode/redeem_points';

    /** @return array{0: User, 1: Vehicle} */
    private function scene(float $balance = 50, float $threshold = 5, bool $active = true): array
    {
        $world = $this->makeWorld();

        LoyaltyProgram::withoutGlobalScopes()->create([
            'sacco_id' => $world['sacco']->id,
            'is_active' => $active,
            'redemption_threshold' => $threshold,
            'point_value' => 30, // the KES 150 fare named below costs 5 points
            'divisor' => 100,
        ]);

        $user = $this->makeUser();
        LoyaltyAccount::withoutGlobalScopes()->create([
            'user_id' => $user->id, 'sacco_id' => $world['sacco']->id, 'balance' => $balance,
        ]);

        return [$user, $world['vehicle']];
    }

    #[Test]
    public function a_passenger_pays_by_scanning_a_bus_that_is_in_no_queue(): void
    {
        // THE WHOLE POINT. No booking exists, no queue exists, and the ride is paid.
        [$user, $vehicle] = $this->scene(balance: 50, threshold: 5);

        Sanctum::actingAs($user);
        $this->postJson(self::URL, ['vehicle_id' => $vehicle->id, 'amount' => 150])
            ->assertOk()
            ->assertJsonPath('points_spent', 5)
            ->assertJsonPath('balance', 45)
            ->assertJsonPath('replay', false);

        $this->assertEqualsWithDelta(45, (float) LoyaltyAccount::withoutGlobalScopes()
            ->where('user_id', $user->id)->value('balance'), 0.001);

        $receipt = QrcodePayment::where('user_id', $user->id)->where('vehicle_id', $vehicle->id)->first();
        $this->assertNotNull($receipt, 'a scan payment needs the same receipt an M-Pesa QR payment writes');
        $this->assertEqualsWithDelta(0, (float) $receipt->amount, 0.001,
            'zero shillings is the honest number — the cost was points, and points live on the ledger');

        $ledger = LoyaltyTransaction::withoutGlobalScopes()
            ->where('source_type', 'qrcode_payment')->where('source_id', $receipt->id)
            ->where('type', LoyaltyTransactionType::Redeemed->value)->first();
        $this->assertNotNull($ledger, 'the spend is keyed on the receipt, which is what makes it idempotent');
        $this->assertEqualsWithDelta(-5, (float) $ledger->value, 0.001);
        $this->assertNull($ledger->booking_id, 'there is no booking in this flow');
    }

    #[Test]
    public function scanning_twice_in_a_row_does_not_charge_twice(): void
    {
        // The retry, which on a matatu is the common case. The printed QR is
        // static, so nothing in the request distinguishes a retry from a new ride
        // — the receipt does.
        [$user, $vehicle] = $this->scene(balance: 50, threshold: 5);

        Sanctum::actingAs($user);
        $this->postJson(self::URL, ['vehicle_id' => $vehicle->id, 'amount' => 150])->assertOk()
            ->assertJsonPath('replay', false);
        $this->postJson(self::URL, ['vehicle_id' => $vehicle->id, 'amount' => 150])->assertOk()
            ->assertJsonPath('points_spent', 5)
            ->assertJsonPath('replay', true);

        $this->assertEqualsWithDelta(45, (float) LoyaltyAccount::withoutGlobalScopes()
            ->where('user_id', $user->id)->value('balance'), 0.001, 'a retry must not debit twice');
        $this->assertSame(1, QrcodePayment::where('user_id', $user->id)->count(),
            'and must not write a second receipt');
    }

    #[Test]
    public function a_receipt_already_on_the_ledger_is_the_replay_whatever_wrote_it(): void
    {
        // The replay is keyed on the rows themselves -- a recent receipt whose id
        // is on the ledger as a Redeemed source -- not on anything a previous
        // request cached. Written here by hand, a minute old, as if by another
        // request whose response was lost.
        [$user, $vehicle] = $this->scene(balance: 45, threshold: 5);
        $saccoId = (int) $vehicle->sacco_id;

        $receipt = QrcodePayment::create([
            'vehicle_id' => $vehicle->id, 'user_id' => $user->id, 'amount' => 0, 'status' => true,
        ]);
        QrcodePayment::whereKey($receipt->id)->update(['created_at' => Carbon::now()->subMinute()]);
        LoyaltyTransaction::withoutGlobalScopes()->create([
            'user_id' => $user->id, 'sacco_id' => $saccoId, 'value' => -5,
            'type' => LoyaltyTransactionType::Redeemed, 'source_type' => 'qrcode_payment', 'source_id' => $receipt->id,
        ]);

        Sanctum::actingAs($user);
        $this->postJson(self::URL, ['vehicle_id' => $vehicle->id, 'amount' => 150])
            ->assertOk()
            ->assertJsonPath('replay', true)
            ->assertJsonPath('payment_id', $receipt->id)
            ->assertJsonPath('points_spent', 5)
            ->assertJsonPath('balance', 45);

        $this->assertEqualsWithDelta(45, (float) LoyaltyAccount::withoutGlobalScopes()
            ->where('user_id', $user->id)->value('balance'), 0.001, 'a replay moves nothing');
        $this->assertSame(1, QrcodePayment::where('user_id', $user->id)->count());
    }

    #[Test]
    public function a_scan_that_waits_on_the_lock_sees_the_receipt_written_while_it_waited(): void
    {
        // THE DOUBLE-CHARGE. Two taps in flight at once -- a double-tap, or a retry
        // racing the request it retries. Both pass the replay check before the
        // transaction, because neither has written anything yet; without a lock
        // both then write a receipt and both debit. Balance 10, threshold 5, two
        // frames of the same finger: 0 points, two receipts, two Redeemed rows.
        //
        // The passenger's ACCOUNT ROW is what exists before either request writes
        // anything, so it is what they serialise on: SELECT ... FOR UPDATE, and the
        // replay check repeated while holding it. The second request waits on the
        // lock until the first commits, then finds the first's receipt.
        //
        // PHPUnit cannot run two requests at once, so the wait is staged: the moment
        // this request takes the lock, "the other tap" lands its receipt, its ledger
        // row and its debit -- which is exactly the state the second of two real
        // requests finds when the lock is released to it. Without the lock query
        // the stage never fires, and this request charges the ride a second time.
        [$user, $vehicle] = $this->scene(balance: 50, threshold: 5);
        $saccoId = (int) $vehicle->sacco_id;

        $staged = false;
        $other = null;
        DB::listen(function (QueryExecuted $q) use (&$staged, &$other, $user, $vehicle, $saccoId) {
            if ($staged || ! str_contains($q->sql, 'loyalty_accounts') || ! str_contains($q->sql, 'for update')) {
                return;
            }
            $staged = true; // before writing anything: the writes below fire this listener too
            $other = QrcodePayment::create([
                'vehicle_id' => $vehicle->id, 'user_id' => $user->id, 'amount' => 0, 'status' => true,
            ]);
            LoyaltyTransaction::withoutGlobalScopes()->create([
                'user_id' => $user->id, 'sacco_id' => $saccoId, 'value' => -5,
                'type' => LoyaltyTransactionType::Redeemed, 'source_type' => 'qrcode_payment', 'source_id' => $other->id,
            ]);
            LoyaltyAccount::withoutGlobalScopes()->where('user_id', $user->id)->where('sacco_id', $saccoId)
                ->decrement('balance', 5);
        });

        Sanctum::actingAs($user);
        $this->postJson(self::URL, ['vehicle_id' => $vehicle->id, 'amount' => 150])
            ->assertOk()
            ->assertJsonPath('replay', true)
            ->assertJsonPath('balance', 45);

        $this->assertNotNull($other, 'the account row must be locked before the replay check, or there is nothing to serialise on');
        $this->assertEqualsWithDelta(45, (float) LoyaltyAccount::withoutGlobalScopes()
            ->where('user_id', $user->id)->value('balance'), 0.001, 'one ride, one debit');
        $this->assertSame(1, QrcodePayment::where('user_id', $user->id)->count(), 'one ride, one receipt');
        $this->assertSame(1, LoyaltyTransaction::withoutGlobalScopes()
            ->where('user_id', $user->id)->where('type', LoyaltyTransactionType::Redeemed->value)->count(),
            'one ride, one Redeemed row');
    }

    #[Test]
    public function boarding_the_same_bus_again_later_is_a_new_ride_and_costs_again(): void
    {
        // The window is a retry window, not a free pass. Past it, the passenger
        // really has boarded again.
        [$user, $vehicle] = $this->scene(balance: 50, threshold: 5);

        Sanctum::actingAs($user);
        $this->postJson(self::URL, ['vehicle_id' => $vehicle->id, 'amount' => 150])->assertOk();

        QrcodePayment::where('user_id', $user->id)
            ->update(['created_at' => Carbon::now()->subHours(2)]);

        $this->postJson(self::URL, ['vehicle_id' => $vehicle->id, 'amount' => 150])
            ->assertOk()
            ->assertJsonPath('replay', false);

        $this->assertEqualsWithDelta(40, (float) LoyaltyAccount::withoutGlobalScopes()
            ->where('user_id', $user->id)->value('balance'), 0.001);
        $this->assertSame(2, QrcodePayment::where('user_id', $user->id)->count());
    }

    #[Test]
    public function too_few_points_leaves_no_receipt_behind(): void
    {
        // The receipt is written before the debit, so a refusal must roll the
        // whole thing back rather than leave an orphan that looks like a paid ride.
        [$user, $vehicle] = $this->scene(balance: 2, threshold: 5);

        Sanctum::actingAs($user);
        $this->postJson(self::URL, ['vehicle_id' => $vehicle->id, 'amount' => 150])
            ->assertStatus(422)
            ->assertJsonPath('error', 'This ride costs 5 points and you have 2.');

        $this->assertSame(0, QrcodePayment::where('user_id', $user->id)->count(),
            'a failed redemption must not leave a receipt claiming the ride was paid');
        $this->assertEqualsWithDelta(2, (float) LoyaltyAccount::withoutGlobalScopes()
            ->where('user_id', $user->id)->value('balance'), 0.001);
    }

    #[Test]
    public function a_sacco_with_no_active_program_cannot_be_paid_in_points(): void
    {
        [$user, $vehicle] = $this->scene(balance: 50, threshold: 5, active: false);

        Sanctum::actingAs($user);
        $this->postJson(self::URL, ['vehicle_id' => $vehicle->id, 'amount' => 150])
            ->assertStatus(422)
            ->assertJsonPath('error', 'This SACCO has no active loyalty program.');
    }

    #[Test]
    public function it_spends_the_callers_own_points_and_needs_authentication(): void
    {
        // An earlier version took a client-supplied phone, which let anyone drain
        // another number's balance. There is no longer any way to name a payer.
        [$user, $vehicle] = $this->scene();

        $this->postJson(self::URL, ['vehicle_id' => $vehicle->id, 'amount' => 150])->assertStatus(401);

        $other = $this->makeUser();
        Sanctum::actingAs($other);
        $this->postJson(self::URL, ['vehicle_id' => $vehicle->id, 'amount' => 150, 'user_id' => $user->id])
            ->assertStatus(422);

        $this->assertEqualsWithDelta(50, (float) LoyaltyAccount::withoutGlobalScopes()
            ->where('user_id', $user->id)->value('balance'), 0.001,
            "another passenger's balance must be untouchable");
    }

    #[Test]
    public function the_scan_screen_reports_the_balance_the_payment_will_actually_spend(): void
    {
        // getVehicle and redeem_points read the SAME balance now. They used to
        // disagree: the screen read the legacy `points` table (empty, so always
        // "no points") while the payment spent from loyalty_accounts.
        [$user, $vehicle] = $this->scene(balance: 50, threshold: 5);
        $vehicle->forceFill(['till_number' => '7100466'])->save();

        Sanctum::actingAs($user);
        $body = $this->postJson('/api/auth/qrcode/vehicle', ['till_number' => '7100466'])
            ->assertOk()->json();

        $this->assertSame(50.0, (float) $body['loyalty']['balance']);
        $this->assertSame(5.0, (float) $body['loyalty']['redemption_threshold']);
        $this->assertTrue($body['loyalty']['eligible_to_redeem']);
    }
}
