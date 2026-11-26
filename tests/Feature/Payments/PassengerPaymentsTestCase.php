<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use App\Enums\BookingCancellationReason;
use App\Enums\UserType;
use App\Models\Booking;
use App\Models\LoyaltyProgram;
use App\Models\LoyaltyTransaction;
use App\Models\MpesaBookingCallback;
use App\Models\MpesaPaymentSetting;
use App\Models\MpesaQrcodePayment;
use App\Models\MpesaStkCallback;
use App\Models\QrcodePayment;
use App\Models\Queue;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Queues\QueueTestCase;

/**
 * Fixtures for the passenger Payments tab: payments shaped as the real write
 * paths leave them (a push row per prompt, a receipt per settlement, a ledger
 * row per refund or redemption), so the tests read what production reads.
 */
abstract class PassengerPaymentsTestCase extends QueueTestCase
{
    /** @var array<string, mixed> */
    protected array $world;

    protected Queue $queue;

    protected function setUp(): void
    {
        parent::setUp();

        $this->world = $this->makeWorld();
        $this->queue = $this->makeQueue(
            $this->world['vehicle'],
            $this->world['terminus'],
            $this->world['route'],
            $this->makeQueueStatus('Pending', 'Pending'),
            $this->world['owner'],
        );
    }

    protected function passenger(): User
    {
        $user = $this->makeUser();
        $user->forceFill(['type' => UserType::Passenger])->save();

        return $user->fresh();
    }

    /** Daraja keys for the world's SACCO, so its bus can be pushed and queried. */
    protected function darajaKeys(): void
    {
        MpesaPaymentSetting::create([
            'sacco_id' => $this->world['sacco']->id,
            'consumer_key' => 'ck', 'consumer_secret' => 'cs',
            'pass_key' => 'pk', 'business_short_code' => '174379',
            'payment_mode' => 'CustomerPayBillOnline', 'is_live' => false, 'status' => true,
        ]);
    }

    protected function loyaltyProgram(): LoyaltyProgram
    {
        return LoyaltyProgram::create([
            'sacco_id' => $this->world['sacco']->id,
            'divisor' => 100,
            'redemption_threshold' => 1,
            'point_value' => 1,
            'is_active' => true,
        ]);
    }

    protected function qrFare(User $user, float $amount = 100, bool $paid = false, array $extra = []): QrcodePayment
    {
        return QrcodePayment::create(array_merge([
            'vehicle_id' => $this->world['vehicle']->id,
            'user_id' => $user->id,
            'amount' => $amount,
            'status' => $paid,
        ], $extra));
    }

    /** A prompt as Daraja accepted it: checkout id on the row and in the stored answer. */
    protected function push(array $owner, ?string $checkout = null, array $extra = []): MpesaStkCallback
    {
        $checkout ??= 'ws_CO_'.$this->nextSequence().'TEST';

        $push = new MpesaStkCallback;
        $push->forceFill(array_merge($owner, [
            'callback_nonce' => bin2hex(random_bytes(32)),
            'checkout_request_id' => $checkout,
            'callback' => json_encode(['CheckoutRequestID' => $checkout, 'ResponseCode' => '0']),
        ], $extra));
        $push->save();

        return $push;
    }

    protected function qrReceipt(QrcodePayment $fare, string $receipt): MpesaQrcodePayment
    {
        return MpesaQrcodePayment::create([
            'transid' => $receipt, 'amount' => $fare->amount, 'phone' => '254700111222',
            'transdate' => Carbon::now(), 'qrcode_payment_id' => $fare->id, 'callback' => '{}',
        ]);
    }

    protected function booking(User $user, array $attributes = []): Booking
    {
        $booking = $this->makeBooking($this->queue, $user, $this->world['from'], $this->world['to']);
        if ($attributes !== []) {
            $booking->forceFill($attributes)->save();
        }

        return $booking->fresh();
    }

    protected function bookingReceipt(Booking $booking, string $receipt): MpesaBookingCallback
    {
        return MpesaBookingCallback::create([
            'transid' => $receipt, 'amount' => $booking->amount, 'phone' => '254700111222',
            'transdate' => Carbon::now(), 'booking_id' => $booking->id, 'callback' => '{}',
        ]);
    }

    protected function ledger(User $user, string $type, float $value, array $owner): LoyaltyTransaction
    {
        return LoyaltyTransaction::withoutGlobalScopes()->create(array_merge([
            'user_id' => $user->id,
            'sacco_id' => $this->world['sacco']->id,
            'value' => $value,
            'type' => $type,
        ], $owner));
    }

    /** Cancel through the query builder, as the sweeps and notBoarded do. */
    protected function cancelBooking(Booking $booking, BookingCancellationReason $reason): void
    {
        DB::table('bookings')->where('id', $booking->id)->update([
            'status' => false,
            'cancellation_reason' => $reason->value,
            'cancelled_at' => now(),
        ]);
    }

    /** Stamp a row's created_at directly, past any model hook. */
    protected function age(string $table, int $id, Carbon $at): void
    {
        DB::table($table)->where('id', $id)->update(['created_at' => $at]);
    }

    /** A Daraja STK callback body. */
    protected function callbackBody(string $checkout, int $resultCode, float $amount = 100, string $receipt = 'UJ6BJ9BXGZ', ?string $desc = null): string
    {
        $callback = [
            'MerchantRequestID' => 'merch-1',
            'CheckoutRequestID' => $checkout,
            'ResultCode' => $resultCode,
            'ResultDesc' => $desc ?? ($resultCode === 0 ? 'The service request is processed successfully.' : 'Request cancelled by user'),
        ];
        if ($resultCode === 0) {
            $callback['CallbackMetadata'] = ['Item' => [
                ['Name' => 'Amount', 'Value' => $amount],
                ['Name' => 'MpesaReceiptNumber', 'Value' => $receipt],
                ['Name' => 'TransactionDate', 'Value' => Carbon::now('Africa/Nairobi')->format('YmdHis')],
                ['Name' => 'PhoneNumber', 'Value' => 254700111222],
            ]];
        }

        return json_encode(['Body' => ['stkCallback' => $callback]], JSON_THROW_ON_ERROR);
    }

    protected function postCallback(MpesaStkCallback $push, string $body): TestResponse
    {
        return $this->call('POST', '/api/testing/stk/push/response/'.$push->callback_nonce, [], [], [], [], $body);
    }
}
