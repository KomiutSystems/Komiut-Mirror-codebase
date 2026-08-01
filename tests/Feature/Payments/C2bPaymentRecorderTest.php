<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use App\Models\Mpesa;
use App\Models\Transaction;
use App\Models\Vehicle;
use App\Services\Mpesa\C2bPaymentRecorder;
use Illuminate\Support\Facades\Context;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Queues\QueueTestCase;

/**
 * C2bPaymentRecorder — the shared save chain for till/paybill confirmations.
 *
 * The behaviours here are exactly the ones whose absence lost 52 real payments
 * on the legacy backend: a bad field must not throw the whole save away, a
 * replayed confirmation must not double-record, and a payment for an unknown
 * vehicle must still be RECORDED (visible, fixable) rather than vanish.
 */
final class C2bPaymentRecorderTest extends QueueTestCase
{
    private function recorder(): C2bPaymentRecorder
    {
        return app(C2bPaymentRecorder::class);
    }

    private function fields(array $o = []): array
    {
        return array_merge([
            'TransID' => 'TX' . $this->nextSequence(),
            'TransAmount' => 200,
            'TransTime' => '20260730120000',
            'BusinessShortCode' => '5202020',
            'MSISDN' => '254700111222',
            'BillRefNumber' => 'KDA123X',
        ], $o);
    }

    protected function setUp(): void
    {
        parent::setUp();
        Context::add('brand', 'testing'); // recorder runs inside a branded request
    }

    #[Test]
    public function it_records_a_payment_and_links_it_to_the_vehicle(): void
    {
        $world = $this->makeWorld();
        $world['vehicle']->merchant_short_code = '5202020';
        $world['vehicle']->save();

        $result = $this->recorder()->record(
            $this->fields(['TransID' => 'REAL1']),
            fn (string $sc) => Vehicle::where('merchant_short_code', $sc)->first()
        );

        $this->assertTrue($result->ok);
        $this->assertDatabaseHas('mpesas', ['TransID' => 'REAL1']);
        $tx = Transaction::where('mpesa_id', $result->mpesa->id)->first();
        $this->assertSame($world['vehicle']->id, $tx->vehicle_id);
        $this->assertDatabaseHas('summaries', ['vehicle_id' => $world['vehicle']->id]);
    }

    #[Test]
    public function an_unparseable_transtime_does_not_throw_away_the_payment(): void
    {
        // The legacy bug: Carbon::parse on a malformed TransTime threw mid-save,
        // Safaricom got a 500, and the payment was lost. It must be recorded.
        $result = $this->recorder()->record(
            $this->fields(['TransID' => 'BADTIME1', 'TransTime' => 'not-a-date']),
            fn () => null
        );

        $this->assertTrue($result->ok);
        $this->assertDatabaseHas('mpesas', ['TransID' => 'BADTIME1']);
    }

    #[Test]
    public function a_payment_for_an_unknown_vehicle_is_still_recorded(): void
    {
        // Recorded-without-link (visible, fixable) beats vanished (unfixable).
        $result = $this->recorder()->record(
            $this->fields(['TransID' => 'NOVEHICLE1']),
            fn () => null
        );

        $this->assertTrue($result->ok);
        $this->assertDatabaseHas('mpesas', ['TransID' => 'NOVEHICLE1']);
        $this->assertDatabaseHas('transactions', ['mpesa_id' => $result->mpesa->id, 'vehicle_id' => null]);
    }

    #[Test]
    public function a_replayed_transid_does_not_double_record(): void
    {
        $f = $this->fields(['TransID' => 'DUP1']);

        $first = $this->recorder()->record($f, fn () => null);
        $second = $this->recorder()->record($f, fn () => null);

        $this->assertTrue($first->ok);
        $this->assertTrue($second->ok);
        $this->assertTrue($second->wasDuplicate);
        $this->assertSame(1, Mpesa::where('TransID', 'DUP1')->count());
        // withoutGlobalScopes: the vehicle-less transaction is hidden by the
        // brand scope otherwise (the same reason the recorder bypasses it).
        $this->assertSame(1, Transaction::withoutGlobalScopes()->where('mpesa_id', $first->mpesa->id)->count());
    }

    #[Test]
    public function a_zero_amount_is_rejected(): void
    {
        $result = $this->recorder()->record($this->fields(['TransAmount' => 0]), fn () => null);

        $this->assertFalse($result->ok);
    }
}
