<?php

declare(strict_types=1);

namespace Tests\Feature\Queues;

use App\Models\Booking;
use App\Models\MpesaPaymentSetting;
use App\Models\MpesaStkCallback;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;

/**
 * Daraja polling / reconciliation (payments:reconcile).
 *
 * When an STK callback is lost or delayed, querying Safaricom (STK Push Query)
 * confirms a genuinely-paid booking instead of letting the 2-minute sweep cancel
 * it — and never confirms a cancelled/failed one.
 */
final class MpesaReconcileTest extends QueueTestCase
{
    private function worldWithMpesa(): array
    {
        $world = $this->makeWorld();
        MpesaPaymentSetting::create([
            'sacco_id' => $world['sacco']->id,
            'consumer_key' => 'ck', 'consumer_secret' => 'cs',
            'pass_key' => 'pk', 'business_short_code' => '174379',
            'payment_mode' => 'CustomerPayBillOnline', 'is_live' => false, 'status' => true,
        ]);

        return $world;
    }

    /** @return array{0: Booking, 1: MpesaStkCallback} */
    private function pendingPush(array $world): array
    {
        $status = $this->makeQueueStatus('Pending', 'Pending');
        $user = $this->makeUser([], $world['sacco']);
        $queue = $this->makeQueue($world['vehicle'], $world['terminus'], $world['route'], $status, $world['owner']);

        $booking = Booking::create([
            'name' => 'Wanjiku', 'phone' => '254700111222', 'passengers' => 1,
            'user_id' => $user->id, 'queue_id' => $queue->id,
            'from_id' => $world['from']->id, 'to_id' => $world['to']->id,
            'amount' => 200, 'created_by' => $user->id,
        ]);

        $rec = MpesaStkCallback::create([
            'booking_id' => $booking->id,
            'callback_nonce' => bin2hex(random_bytes(8)),
            'callback' => json_encode(['CheckoutRequestID' => 'ws_CO_TEST123', 'MerchantRequestID' => 'm1']),
        ]);
        $rec->created_at = now()->subMinutes(3);   // past the grace window
        $rec->save();

        return [$booking, $rec];
    }

    #[Test]
    public function a_lost_callback_is_recovered_by_querying_daraja(): void
    {
        $world = $this->worldWithMpesa();
        [$booking, $rec] = $this->pendingPush($world);

        Http::fake([
            '*oauth/v1/generate*' => Http::response(['access_token' => 'tok', 'expires_in' => '3599']),
            '*stkpushquery*' => Http::response(['ResponseCode' => '0', 'ResultCode' => '0', 'ResultDesc' => 'processed successfully']),
        ]);

        $this->artisan('payments:reconcile')->assertSuccessful();

        $this->assertTrue((bool) $booking->fresh()->paid, 'a paid-but-callback-lost booking must be confirmed');
        $this->assertNotNull($rec->fresh()->processed_at);
        $this->assertDatabaseHas('mpesa_booking_callbacks', ['booking_id' => $booking->id]);
    }

    #[Test]
    public function a_cancelled_stk_is_closed_out_not_confirmed(): void
    {
        $world = $this->worldWithMpesa();
        [$booking, $rec] = $this->pendingPush($world);

        Http::fake([
            '*oauth/v1/generate*' => Http::response(['access_token' => 'tok']),
            '*stkpushquery*' => Http::response(['ResultCode' => '1032', 'ResultDesc' => 'Request cancelled by user']),
        ]);

        $this->artisan('payments:reconcile')->assertSuccessful();

        $this->assertFalse((bool) $booking->fresh()->paid, 'a cancelled STK must never be confirmed');
        $this->assertNotNull($rec->fresh()->processed_at, 'a cancelled push should stop being reconciled');
    }

    #[Test]
    public function a_still_processing_query_is_left_for_the_next_run(): void
    {
        $world = $this->worldWithMpesa();
        [$booking, $rec] = $this->pendingPush($world);

        Http::fake([
            '*oauth/v1/generate*' => Http::response(['access_token' => 'tok']),
            '*stkpushquery*' => Http::response(['ResultCode' => '1037', 'ResultDesc' => 'DS timeout, still processing']),
        ]);

        $this->artisan('payments:reconcile')->assertSuccessful();

        $this->assertFalse((bool) $booking->fresh()->paid);
        $this->assertNull($rec->fresh()->processed_at, '1037 must be retried, not closed out');
    }
}
