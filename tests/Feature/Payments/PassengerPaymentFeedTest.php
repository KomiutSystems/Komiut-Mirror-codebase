<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use App\Enums\BookingCancellationReason;
use App\Enums\PaymentMethod;
use App\Models\MpesaBookingCallback;
use App\Services\Payments\PassengerPayments\PassengerPaymentFeed;
use App\Services\Payments\PassengerPayments\PaymentStatus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;

/**
 * GET passenger/payments and passenger/payments/{id}: every in-app payment the
 * caller made, both kinds, every status -- and nobody else's.
 */
final class PassengerPaymentFeedTest extends PassengerPaymentsTestCase
{
    private const LIST = '/api/auth/passenger/payments';

    private const ROW_KEYS = [
        'id', 'kind', 'status', 'method', 'amount', 'currency', 'points_spent', 'mpesa_receipt',
        'status_message', 'plate', 'sacco', 'route', 'pickup', 'dropoff', 'passengers', 'booking_id',
        'checkout_request_id', 'created_at', 'settled_at', 'updated_at',
    ];

    #[Test]
    public function the_caller_sees_their_own_bookings_and_qr_fares_and_nothing_else(): void
    {
        $me = $this->passenger();
        $other = $this->passenger();

        $fare = $this->qrFare($me, 100);
        $this->push(['qrcode_payment_id' => $fare->id]);
        $booking = $this->booking($me, ['paid' => true, 'payment_method' => PaymentMethod::Mpesa]);
        $this->bookingReceipt($booking, 'QGH7XY12ZK');

        $theirs = $this->qrFare($other, 300, true);
        $this->qrReceipt($theirs, 'THEIRS0001');
        $this->booking($other);

        Sanctum::actingAs($me);
        $response = $this->getJson(self::LIST)->assertOk();

        $ids = array_column($response->json('payments'), 'id');
        sort($ids);
        $this->assertSame(['booking:'.$booking->id, 'qr_fare:'.$fare->id], $ids);
        $this->assertNull($response->json('next_cursor'));

        foreach ($response->json('payments') as $row) {
            $this->assertSame(self::ROW_KEYS, array_keys($row), 'the row is the contract, key for key');
            $this->assertStringNotContainsString('254700', json_encode($row), 'no phone number, not even masked');
        }
    }

    #[Test]
    public function a_qr_fare_reads_as_the_contract_example(): void
    {
        $me = $this->passenger();
        $fare = $this->qrFare($me, 100);
        $push = $this->push(['qrcode_payment_id' => $fare->id], 'ws_CO_261120261030');

        Sanctum::actingAs($me);
        $pending = $this->getJson(self::LIST.'/qr_fare:'.$fare->id)->assertOk();
        $pending->assertJsonPath('status', 'pending')
            ->assertJsonPath('checkout_request_id', 'ws_CO_261120261030')
            ->assertJsonPath('settled_at', null)
            ->assertJsonPath('mpesa_receipt', null)
            ->assertJsonPath('status_message', null);

        $this->postCallback($push, $this->callbackBody('ws_CO_261120261030', 0, 100, 'UJ6BJ9BXGZ'))->assertOk();

        $paid = $this->getJson(self::LIST.'/qr_fare:'.$fare->id)->assertOk();
        $paid->assertJsonPath('id', 'qr_fare:'.$fare->id)
            ->assertJsonPath('kind', 'qr_fare')
            ->assertJsonPath('status', 'paid')
            ->assertJsonPath('method', 'mpesa')
            ->assertJsonPath('amount', 100)
            ->assertJsonPath('currency', 'KES')
            ->assertJsonPath('points_spent', null)
            ->assertJsonPath('mpesa_receipt', 'UJ6BJ9BXGZ')
            ->assertJsonPath('status_message', null)
            ->assertJsonPath('plate', $this->world['vehicle']->plate)
            ->assertJsonPath('sacco', $this->world['sacco']->name)
            // The bus's live run in the day before the scan.
            ->assertJsonPath('route', $this->world['route']->name)
            ->assertJsonPath('pickup', null)
            ->assertJsonPath('passengers', null)
            ->assertJsonPath('booking_id', null)
            ->assertJsonPath('checkout_request_id', null);

        $this->assertStringEndsWith('+03:00', (string) $paid->json('created_at'));
        $this->assertNotNull($paid->json('settled_at'));
        $this->assertSame(array_keys($paid->json()), self::ROW_KEYS);
    }

    #[Test]
    public function another_users_payment_is_a_404_never_a_403(): void
    {
        $me = $this->passenger();
        $other = $this->passenger();
        $theirFare = $this->qrFare($other, 100, true);
        $theirBooking = $this->booking($other);

        Sanctum::actingAs($me);

        $this->getJson(self::LIST.'/qr_fare:'.$theirFare->id)->assertNotFound();
        $this->getJson(self::LIST.'/booking:'.$theirBooking->id)->assertNotFound();
        $this->getJson(self::LIST.'/qr_fare:999999')->assertNotFound();
        $this->getJson(self::LIST.'/'.$theirFare->id)->assertNotFound();
        $this->getJson(self::LIST.'/wallet:1')->assertNotFound();
    }

    #[Test]
    public function staff_who_can_view_passengers_still_get_only_their_own_empty_list(): void
    {
        $passenger = $this->passenger();
        $this->qrFare($passenger, 100, true);
        $this->booking($passenger);

        $staff = $this->makeUser(['View Passengers', 'Edit Passengers', 'View Transactions'], $this->world['sacco']);
        Sanctum::actingAs($staff);

        $this->getJson(self::LIST)->assertOk()->assertExactJson(['payments' => [], 'next_cursor' => null]);
        // No parameter widens it.
        $this->getJson(self::LIST.'?user_id='.$passenger->id.'&sacco='.$this->world['sacco']->id)
            ->assertOk()->assertJsonCount(0, 'payments');
    }

    #[Test]
    public function the_endpoint_needs_a_signed_in_caller(): void
    {
        $this->getJson(self::LIST)->assertUnauthorized();
        $this->getJson(self::LIST.'/qr_fare:1')->assertUnauthorized();
    }

    /**
     * Every status, from data as the write paths leave it -- and the ?status=
     * filter (SQL) agreeing with the row (PHP) on every one of them.
     */
    #[Test]
    public function each_status_maps_from_stored_data_and_the_filter_agrees(): void
    {
        $me = $this->passenger();
        $program = $this->loyaltyProgram();
        $expect = [];

        // QR fares ----------------------------------------------------------
        $f = $this->qrFare($me, 100, true);
        $this->qrReceipt($f, 'UJ6BJ9BXGZ');
        $expect['qr_fare:'.$f->id] = ['paid', null, 'mpesa', 'UJ6BJ9BXGZ'];

        $f = $this->qrFare($me, 0, true, ['fare' => 70]);
        $this->ledger($me, 'redeemed', -70, ['source_type' => 'qrcode_payment', 'source_id' => $f->id]);
        $expect['qr_fare:'.$f->id] = ['paid', null, 'points', null, 70, 70.0];

        $f = $this->qrFare($me, 0, true, ['fare' => 80, 'payment_method' => 'wallet']);
        $expect['qr_fare:'.$f->id] = ['paid', null, 'wallet', null, 80];

        $f = $this->qrFare($me);
        $this->push(['qrcode_payment_id' => $f->id]);
        $expect['qr_fare:'.$f->id] = ['pending', null, 'mpesa'];

        foreach ([1032 => PaymentStatus::MSG_CANCELLED_ON_PHONE, 1 => PaymentStatus::MSG_INSUFFICIENT_FUNDS, 2001 => PaymentStatus::MSG_WRONG_PIN, 1037 => PaymentStatus::MSG_NO_PIN, 9999 => PaymentStatus::MSG_DECLINED] as $code => $message) {
            $f = $this->qrFare($me);
            $this->push(['qrcode_payment_id' => $f->id], null, ['processed_at' => now(), 'result_code' => $code, 'result_desc' => 'raw daraja words']);
            $expect['qr_fare:'.$f->id] = ['failed', $message];
        }

        // A bank's failure carries words, not a Daraja code.
        $f = $this->qrFare($me);
        $this->push(['qrcode_payment_id' => $f->id], null, ['provider' => 'coop', 'processed_at' => now(), 'result_desc' => 'Request cancelled by the customer']);
        $expect['qr_fare:'.$f->id] = ['failed', PaymentStatus::MSG_CANCELLED_ON_PHONE];

        $f = $this->qrFare($me);
        $this->push(['qrcode_payment_id' => $f->id], null, ['cancelled_at' => now()]);
        $expect['qr_fare:'.$f->id] = ['cancelled', PaymentStatus::MSG_CANCELLED_IN_APP];

        $f = $this->qrFare($me);
        $this->push(['qrcode_payment_id' => $f->id], null, ['unconfirmed_at' => now()]);
        $expect['qr_fare:'.$f->id] = ['failed', PaymentStatus::MSG_UNCONFIRMED];

        // Refused outright: Daraja's error body, no checkout id.
        $f = $this->qrFare($me);
        $this->push(['qrcode_payment_id' => $f->id], null, ['checkout_request_id' => null, 'callback' => json_encode(['errorCode' => '400.002.02', 'errorMessage' => 'Bad Request - Invalid PhoneNumber'])]);
        $expect['qr_fare:'.$f->id] = ['failed', PaymentStatus::MSG_NOT_SENT];

        // No answer yet (Co-op's record before the bank replies): pending.
        $f = $this->qrFare($me);
        $this->push(['qrcode_payment_id' => $f->id], null, ['provider' => 'coop', 'checkout_request_id' => null, 'callback' => 'null']);
        $expect['qr_fare:'.$f->id] = ['pending', null];

        // Raised before the sweep existed and never answered.
        $f = $this->qrFare($me);
        $old = $this->push(['qrcode_payment_id' => $f->id]);
        $this->age('mpesa_stk_callbacks', $old->id, now()->subDays(3));
        $expect['qr_fare:'.$f->id] = ['failed', PaymentStatus::MSG_UNCONFIRMED];

        // A fare row whose prompt never got out, and one whose push is in flight.
        $f = $this->qrFare($me);
        $this->age('qrcode_payments', $f->id, now()->subMinutes(5));
        $expect['qr_fare:'.$f->id] = ['failed', PaymentStatus::MSG_NOT_SENT];
        $f = $this->qrFare($me);
        $expect['qr_fare:'.$f->id] = ['pending', null];

        // Bookings ----------------------------------------------------------
        $b = $this->booking($me, ['paid' => true, 'payment_method' => PaymentMethod::Mpesa]);
        $this->bookingReceipt($b, 'BKG0000001');
        $expect['booking:'.$b->id] = ['paid', null, 'mpesa', 'BKG0000001'];

        // Payment rows missing: still listed, read from the booking.
        $b = $this->booking($me, ['paid' => true]);
        $expect['booking:'.$b->id] = ['paid', null, 'mpesa', null];

        $b = $this->booking($me, ['paid' => true, 'payment_method' => PaymentMethod::LoyaltyPoints]);
        $this->ledger($me, 'redeemed', -200, ['booking_id' => $b->id]);
        $expect['booking:'.$b->id] = ['paid', null, 'points', null, 200, 200.0];

        $b = $this->booking($me, ['paid' => true, 'payment_method' => PaymentMethod::Mpesa]);
        $this->bookingReceipt($b, 'BKG0000002');
        $this->cancelBooking($b, BookingCancellationReason::NoShow);
        $this->ledger($me, 'refunded', 200, ['booking_id' => $b->id]);
        $expect['booking:'.$b->id] = ['refunded', PaymentStatus::MSG_REFUNDED_NO_SHOW, 'mpesa', 'BKG0000002'];

        $b = $this->booking($me, ['paid' => true, 'payment_method' => PaymentMethod::LoyaltyPoints]);
        $this->ledger($me, 'redeemed', -200, ['booking_id' => $b->id]);
        $this->cancelBooking($b, BookingCancellationReason::TripOver);
        $this->ledger($me, 'refunded', 200, ['booking_id' => $b->id]);
        $expect['booking:'.$b->id] = ['refunded', PaymentStatus::MSG_REFUNDED_TRIP_OVER, 'points'];

        $b = $this->booking($me);
        $expect['booking:'.$b->id] = ['pending', null];

        $b = $this->booking($me);
        $this->push(['booking_id' => $b->id]);
        $this->cancelBooking($b, BookingCancellationReason::Expired);
        $expect['booking:'.$b->id] = ['pending', null];

        $b = $this->booking($me);
        $this->cancelBooking($b, BookingCancellationReason::Cancelled);
        $expect['booking:'.$b->id] = ['cancelled', PaymentStatus::MSG_BOOKING_CANCELLED];

        $b = $this->booking($me);
        $this->cancelBooking($b, BookingCancellationReason::Expired);
        $expect['booking:'.$b->id] = ['failed', PaymentStatus::MSG_BOOKING_EXPIRED];

        $b = $this->booking($me);
        $this->cancelBooking($b, BookingCancellationReason::TripOver);
        $expect['booking:'.$b->id] = ['failed', PaymentStatus::MSG_TRIP_ENDED_UNPAID];

        $b = $this->booking($me);
        $this->push(['booking_id' => $b->id], null, ['processed_at' => now(), 'result_code' => 2001]);
        $expect['booking:'.$b->id] = ['failed', PaymentStatus::MSG_WRONG_PIN];

        $b = $this->booking($me);
        $this->push(['booking_id' => $b->id], null, ['cancelled_at' => now()]);
        $expect['booking:'.$b->id] = ['cancelled', PaymentStatus::MSG_CANCELLED_IN_APP];

        Sanctum::actingAs($me);

        foreach ($expect as $id => $want) {
            $row = $this->getJson(self::LIST.'/'.$id)->assertOk()->json();
            $this->assertSame($want[0], $row['status'], $id.' status');
            $this->assertSame($want[1], $row['status_message'], $id.' status_message');
            if (isset($want[2])) {
                $this->assertSame($want[2], $row['method'], $id.' method');
            }
            if (array_key_exists(3, $want)) {
                $this->assertSame($want[3], $row['mpesa_receipt'], $id.' mpesa_receipt');
            }
            if (isset($want[4])) {
                $this->assertEquals($want[4], $row['amount'], $id.' amount');
            }
            if (isset($want[5])) {
                $this->assertEquals($want[5], $row['points_spent'], $id.' points_spent');
            }
            if (in_array($row['status'], ['paid', 'pending'], true)) {
                $this->assertNull($row['status_message']);
            } else {
                $this->assertContains($row['status_message'], PaymentStatus::MESSAGES);
            }
            $this->assertSame($row['status'] === 'pending', $row['settled_at'] === null, $id.' settled_at only once settled');
        }

        // The filter, which is SQL, lands every row in the same bucket.
        foreach (PaymentStatus::ALL as $status) {
            $listed = [];
            $cursor = null;
            do {
                $page = $this->getJson(self::LIST.'?status='.$status.($cursor ? '&cursor='.$cursor : ''))->assertOk();
                $listed = array_merge($listed, array_column($page->json('payments'), 'id'));
                $cursor = $page->json('next_cursor');
            } while ($cursor !== null);

            $want = array_keys(array_filter($expect, fn ($w) => $w[0] === $status));
            sort($listed);
            sort($want);
            $this->assertSame($want, $listed, "?status={$status} must list exactly the rows that read {$status}");
        }

        $this->getJson(self::LIST.'?status=active')->assertOk()
            ->assertJsonCount(count(array_filter($expect, fn ($w) => $w[0] === 'pending')), 'payments');
        $this->getJson(self::LIST.'?status=processing')->assertStatus(400);
    }

    #[Test]
    public function order_and_cursor_hold_when_a_payment_lands_between_pages(): void
    {
        $me = $this->passenger();
        $base = Carbon::parse('2026-11-20 08:00:00');

        // 25 payments over 13 distinct seconds: ties within and across kinds.
        $made = [];
        for ($i = 0; $i < 25; $i++) {
            $at = $base->copy()->addSeconds(intdiv($i, 2));
            if ($i % 2 === 0) {
                $row = $this->qrFare($me, 100, true);
                $this->age('qrcode_payments', $row->id, $at);
                $made[] = 'qr_fare:'.$row->id;
            } else {
                $row = $this->booking($me, ['paid' => true]);
                $this->age('bookings', $row->id, $at);
                $made[] = 'booking:'.$row->id;
            }
        }

        // A booking and a QR fare with the SAME id and the SAME second.
        $twinAt = $base->copy()->subMinute();
        $fare = $this->qrFare($me, 100, true);
        DB::table('bookings')->insert([
            'id' => $fare->id + 1000, 'name' => 'Twin', 'phone' => '254700111222', 'passengers' => 1,
            'user_id' => $me->id, 'queue_id' => $this->queue->id, 'from_id' => $this->world['from']->id,
            'to_id' => $this->world['to']->id, 'amount' => 100, 'created_by' => $me->id, 'paid' => true,
            'status' => true, 'boarded' => false, 'booking_type' => 'passenger', 'created_at' => $twinAt, 'updated_at' => $twinAt,
        ]);
        DB::table('qrcode_payments')->where('id', $fare->id)->update(['id' => $fare->id + 1000, 'created_at' => $twinAt]);
        $made[] = 'booking:'.($fare->id + 1000);
        $made[] = 'qr_fare:'.($fare->id + 1000);

        Sanctum::actingAs($me);

        $first = $this->getJson(self::LIST)->assertOk();
        $this->assertCount(20, $first->json('payments'));
        $cursor = $first->json('next_cursor');
        $this->assertNotNull($cursor);

        // A new payment lands between the two reads.
        $this->qrFare($me, 100, true);

        $second = $this->getJson(self::LIST.'?cursor='.$cursor)->assertOk();
        $this->assertCount(7, $second->json('payments'));
        $this->assertNull($second->json('next_cursor'));

        $seen = array_merge(array_column($first->json('payments'), 'id'), array_column($second->json('payments'), 'id'));
        $this->assertSame(count($seen), count(array_unique($seen)), 'no row on two pages');
        $this->assertEqualsCanonicalizing($made, $seen, 'every row exactly once');

        // Newest first; ties by id, then qr_fare before booking.
        $order = DB::query()->fromSub(
            DB::table('bookings')->where('user_id', $me->id)->selectRaw("'booking' as kind, id, created_at")
                ->unionAll(DB::table('qrcode_payments')->where('user_id', $me->id)->selectRaw("'qr_fare' as kind, id, created_at")),
            'k'
        )->where('created_at', '<=', $base->copy()->addMinute())
            ->orderByDesc('created_at')->orderByDesc('id')->orderByDesc('kind')->get()
            ->map(fn ($k) => $k->kind.':'.$k->id)->all();
        $this->assertSame($order, $seen);

        // The twin pair, in that order, adjacent.
        $twin = array_search('qr_fare:'.($fare->id + 1000), $seen, true);
        $this->assertSame('booking:'.($fare->id + 1000), $seen[$twin + 1]);

        // A garbled cursor is page one, not an error.
        $this->getJson(self::LIST.'?cursor=not-a-cursor')->assertOk()->assertJsonCount(20, 'payments');
    }

    #[Test]
    public function from_and_to_are_inclusive_nairobi_days(): void
    {
        $me = $this->passenger();

        // 2026-11-20 00:30 in Nairobi is 2026-11-19 21:30 UTC.
        $early = $this->qrFare($me, 100, true);
        $this->age('qrcode_payments', $early->id, Carbon::parse('2026-11-19 21:30:00', 'UTC'));
        // 2026-11-20 23:59 in Nairobi is 20:59 UTC.
        $late = $this->booking($me, ['paid' => true]);
        $this->age('bookings', $late->id, Carbon::parse('2026-11-20 20:59:00', 'UTC'));
        // 2026-11-21 00:01 Nairobi.
        $next = $this->qrFare($me, 100, true);
        $this->age('qrcode_payments', $next->id, Carbon::parse('2026-11-20 21:01:00', 'UTC'));

        Sanctum::actingAs($me);

        $ids = array_column($this->getJson(self::LIST.'?from=2026-11-20&to=2026-11-20')->assertOk()->json('payments'), 'id');
        $this->assertEqualsCanonicalizing(['qr_fare:'.$early->id, 'booking:'.$late->id], $ids);

        $this->getJson(self::LIST.'?from=20-11-2026')->assertStatus(400);
    }

    #[Test]
    public function a_page_costs_the_same_queries_whatever_it_holds(): void
    {
        $me = $this->passenger();
        $this->booking($me, ['paid' => true]);
        $this->qrFare($me, 100, true);
        Sanctum::actingAs($me);

        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson(self::LIST)->assertOk();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };

        // The first request of a session also loads the caller's roles.
        $count();
        $small = $count();

        for ($i = 0; $i < 12; $i++) {
            $b = $this->booking($me, ['paid' => true, 'payment_method' => PaymentMethod::Mpesa]);
            $this->bookingReceipt($b, 'RCPT'.str_pad((string) $i, 6, '0', STR_PAD_LEFT));
            $f = $this->qrFare($me, 50 + $i);
            $this->push(['qrcode_payment_id' => $f->id]);
        }

        $this->assertSame($small, $count(), 'no query per row');
    }

    #[Test]
    public function the_list_is_throttled_per_passenger(): void
    {
        $me = $this->passenger();
        Sanctum::actingAs($me);

        for ($i = 0; $i < 60; $i++) {
            $this->getJson(self::LIST)->assertOk();
        }
        $this->getJson(self::LIST)->assertStatus(429);
    }

    #[Test]
    public function payments_history_responds_exactly_as_before(): void
    {
        $me = $this->passenger();
        $booking = $this->booking($me, ['paid' => true, 'amount' => 250, 'payment_method' => PaymentMethod::Mpesa]);
        MpesaBookingCallback::create([
            'transid' => 'QGH7XY12ZK', 'amount' => 250, 'phone' => '254700111222',
            'transdate' => Carbon::parse('2026-11-20 10:15:00'), 'booking_id' => $booking->id, 'callback' => '{}',
        ]);
        // Neither of these is a paid booking, so neither was ever in history.
        $this->qrFare($me, 100, true);
        $this->booking($me);

        Sanctum::actingAs($me);

        $this->getJson('/api/auth/payments/history')->assertOk()->assertExactJson([
            'payments' => [[
                'booking_id' => $booking->id,
                'reference' => 'BKG-'.$booking->id,
                'amount' => 250,
                'date' => Carbon::parse('2026-11-20 10:15:00')->toIso8601String(),
                'method' => 'mpesa',
                'mpesa_receipt_number' => 'QGH7XY12ZK',
                'route' => $this->world['from']->name.' - '.$this->world['to']->name,
                'plate' => $this->world['vehicle']->plate,
                'passengers' => 1,
            ]],
            'total' => 1,
            'per_page' => 20,
            'current_page' => 1,
            'last_page' => 1,
        ]);
    }

    #[Test]
    public function the_contract_doc_lists_every_status_message_verbatim(): void
    {
        $doc = (string) file_get_contents(base_path('docs/passenger-payments-api.md'));

        foreach (PaymentStatus::MESSAGES as $sentence) {
            $this->assertStringContainsString('`'.$sentence.'`', $doc);
        }
    }

    #[Test]
    public function the_cursor_round_trips_and_rejects_what_it_did_not_make(): void
    {
        $cursor = PassengerPaymentFeed::encodeCursor('2026-11-20 08:00:01', 42, 'qr_fare');
        $this->assertSame(['2026-11-20 08:00:01', 42, 'qr_fare'], PassengerPaymentFeed::decodeCursor($cursor));

        $this->assertNull(PassengerPaymentFeed::decodeCursor(base64_encode("2026-11-20 08:00:01'; drop table x|42|qr_fare")));
        $this->assertNull(PassengerPaymentFeed::decodeCursor(base64_encode('2026-11-20 08:00:01|42|wallet')));
        $this->assertNull(PassengerPaymentFeed::decodeCursor('%%%'));
    }
}
