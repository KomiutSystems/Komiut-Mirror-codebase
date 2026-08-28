<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use App\Enums\UserType;
use App\Models\MpesaQrcodePayment;
use App\Models\QrcodePayment;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Queues\QueueTestCase;

/**
 * The QR payments screen: Reference, Phone, Amount, Status, Date.
 *
 * TWO OF THOSE FIVE COLUMNS CAME BACK EMPTY. `qrcode_payments` holds the amount,
 * the status and the timestamp, but the M-Pesa receipt number and the payer's
 * phone live in `mpesa_qrcode_payments` — and the listing did not load that
 * relation. Nobody had noticed because the table has zero rows: no QR payment
 * has ever been made on this platform, so the screen renders "No transactions
 * yet" and every column looks fine.
 *
 * These tests exist so the first real scan does not become the bug report.
 */
final class QrPaymentsScreenTest extends QueueTestCase
{
    private const ENDPOINT = '/api/v1/auth/qrcode/payments';

    private function admin(array $world): User
    {
        $u = $this->makeUser(['View Transactions'], $world['sacco']);
        $u->forceFill(['type' => UserType::Admin, 'sacco_id' => $world['sacco']->id])->save();

        return $u->fresh();
    }

    /** A QR payment, optionally completed by a callback. */
    private function payment(array $world, ?User $payer, ?array $settled = null): QrcodePayment
    {
        $p = QrcodePayment::withoutGlobalScopes()->create([
            'vehicle_id' => $world['vehicle']->id,
            'user_id' => $payer?->id,
            'amount' => $settled['amount'] ?? 100,
            'status' => $settled !== null,
        ]);

        if ($settled !== null) {
            MpesaQrcodePayment::create([
                'qrcode_payment_id' => $p->id,
                'transid' => $settled['transid'],
                'phone' => $settled['phone'],
                'name' => $settled['name'] ?? 'Joyce',
                'amount' => $settled['amount'] ?? 100,
                'transdate' => now(),
                'callback' => '{}',
            ]);
        }

        return $p->fresh();
    }

    #[Test]
    public function a_settled_payment_shows_its_receipt_and_the_payers_phone(): void
    {
        // The defect: both of these came back missing, because the relation
        // holding them was never loaded.
        $world = $this->makeWorld();
        $this->payment($world, null, [
            'transid' => 'UHQ434J0C3',
            'phone' => '254712345678',
            'amount' => 150,
        ]);

        Sanctum::actingAs($this->admin($world));

        $row = $this->getJson(self::ENDPOINT.'?date='.now()->toDateString())
            ->assertOk()->json('payments.0');

        $this->assertSame('UHQ434J0C3', $row['reference'], 'the M-Pesa receipt must reach the screen');
        $this->assertSame('254712345678', $row['phone']);
        $this->assertSame(150.0, (float) $row['amount']);
        $this->assertTrue($row['paid']);
    }

    #[Test]
    public function a_push_that_was_never_paid_shows_as_unpaid_with_no_receipt(): void
    {
        // A prompt raised and ignored. There is no receipt because no money
        // moved — the row must still appear, and must not claim to be paid.
        $world = $this->makeWorld();
        $this->payment($world, null, null);

        Sanctum::actingAs($this->admin($world));

        $row = $this->getJson(self::ENDPOINT.'?date='.now()->toDateString())
            ->assertOk()->json('payments.0');

        $this->assertNull($row['reference']);
        $this->assertNull($row['phone']);
        $this->assertFalse($row['paid']);
        $this->assertNotNull($row['date'], 'an unpaid push still has a raised-at time');
    }

    #[Test]
    public function the_row_carries_the_bus_it_was_paid_to(): void
    {
        $world = $this->makeWorld();
        $this->payment($world, null, ['transid' => 'ABC123', 'phone' => '254700000000']);

        Sanctum::actingAs($this->admin($world));

        $row = $this->getJson(self::ENDPOINT.'?date='.now()->toDateString())
            ->assertOk()->json('payments.0');

        $this->assertSame($world['vehicle']->plate, $row['vehicle']);
        $this->assertSame($world['sacco']->name, $row['sacco']);
    }

    #[Test]
    public function the_raw_daraja_callback_is_never_shipped_to_the_client(): void
    {
        // The rows used to be dumped as models: the whole vehicle, its SACCO, its
        // seat layout and the raw callback JSON, several kilobytes a row, none of
        // it on screen.
        $world = $this->makeWorld();
        $this->payment($world, null, ['transid' => 'ABC123', 'phone' => '254700000000']);

        Sanctum::actingAs($this->admin($world));

        $body = $this->getJson(self::ENDPOINT.'?date='.now()->toDateString())->assertOk()->getContent();

        $this->assertStringNotContainsString('callback', $body);
        $this->assertStringNotContainsString('seat_arrangement', $body);
    }

    #[Test]
    public function another_saccos_qr_payments_are_never_listed(): void
    {
        $mine = $this->makeWorld();
        $theirs = $this->makeWorld();

        $this->payment($mine, null, ['transid' => 'MINE01', 'phone' => '254700000001']);
        $this->payment($theirs, null, ['transid' => 'THEIRS1', 'phone' => '254700000002']);

        Sanctum::actingAs($this->admin($mine));

        $refs = array_column(
            $this->getJson(self::ENDPOINT.'?date='.now()->toDateString())->assertOk()->json('payments'),
            'reference'
        );

        $this->assertContains('MINE01', $refs);
        $this->assertNotContains('THEIRS1', $refs, 'the tenant boundary must hold on this screen too');
    }

    #[Test]
    public function a_passenger_sees_only_the_payments_they_made(): void
    {
        // A saccoless caller has no tenant to widen to, so the listing narrows
        // them to their own rows. Their own receipt must still reach them.
        $world = $this->makeWorld();

        $me = $this->makeUser([], null);
        $me->forceFill(['type' => UserType::Passenger])->save();
        $someoneElse = $this->makeUser([], null);
        $someoneElse->forceFill(['type' => UserType::Passenger])->save();

        $this->payment($world, $me->fresh(), ['transid' => 'MINE01', 'phone' => '254700000001']);
        $this->payment($world, $someoneElse->fresh(), ['transid' => 'NOTMINE', 'phone' => '254700000002']);

        Sanctum::actingAs($me->fresh());

        $refs = array_column(
            $this->getJson(self::ENDPOINT.'?date='.now()->toDateString())->assertOk()->json('payments'),
            'reference'
        );

        $this->assertSame(['MINE01'], $refs);
    }

    #[Test]
    public function the_listing_does_not_query_once_per_row(): void
    {
        // The relation is eager-loaded, so a page of 20 is a fixed number of
        // queries rather than 20 extra round trips.
        $world = $this->makeWorld();
        foreach (range(1, 5) as $i) {
            $this->payment($world, null, ['transid' => 'TX'.$i, 'phone' => '25470000000'.$i]);
        }

        Sanctum::actingAs($this->admin($world));

        \Illuminate\Support\Facades\DB::enableQueryLog();
        $this->getJson(self::ENDPOINT.'?date='.now()->toDateString())->assertOk();
        $queries = count(\Illuminate\Support\Facades\DB::getQueryLog());
        \Illuminate\Support\Facades\DB::disableQueryLog();

        $this->assertLessThan(
            15,
            $queries,
            'five rows must not cost five extra queries — the relation is eager-loaded'
        );
    }
}
