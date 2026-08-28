<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use App\Models\Cash;
use App\Models\Mpesa;
use App\Models\MpesaQrcodePayment;
use App\Models\QrcodePayment;
use App\Models\Sacco;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Queues\QueueTestCase;

/**
 * A QR payment must read as a QR payment on the screens where the money is.
 *
 * The STK callback writes qrcode_payments + mpesa_qrcode_payments; the money
 * itself lands on the till and is written separately into mpesas + transactions
 * by the C2B confirmation. The only link is
 * mpesa_qrcode_payments.transid = mpesas.TransID, and until now nothing joined
 * them — so /transactions and /transactions/mpesa showed a scanned payment as an
 * ordinary till payment.
 *
 * These tests pin: the marking, the narrowing filter, that ONE query resolves a
 * whole page (never one per row), and that no pre-existing response key moved.
 */
final class QrPaymentSourceTest extends QueueTestCase
{
    private const TRANSACTIONS = '/api/v1/auth/transactions';

    private const MPESA_LISTING = '/api/v1/auth/transactions/mpesa';

    private function admin(Sacco $sacco): User
    {
        return $this->makeUser(['View Transactions'], $sacco);
    }

    private function vehicleFor(Sacco $sacco, string $plate): Vehicle
    {
        $vehicle = $this->makeVehicle($sacco, $this->makeUser([], $sacco), $this->makeSeat());
        $vehicle->update(['plate' => $plate, 'till_number' => '4321087', 'merchant_short_code' => '4321075']);

        return $vehicle;
    }

    /** A till payment: the C2B rail, exactly as the confirmation writes it. */
    private function tillPayment(Vehicle $vehicle, string $transId, float $amount = 80): Mpesa
    {
        $mpesa = Mpesa::create([
            'TransID' => $transId,
            'MSISDN' => '254700111222',
            'TransAmount' => $amount,
            'TransTime' => now(),
            'FirstName' => 'MUNIRA',
            'LastName' => 'PAYER',
            'BusinessShortCode' => '5557936',
            'BillRefNumber' => $vehicle->till_number,
            'TransactionType' => 'Pay Bill',
        ]);

        Transaction::create([
            'mpesa_id' => $mpesa->id,
            'vehicle_id' => $vehicle->id,
            'amount' => $amount,
            'trans_date' => now(),
        ]);

        return $mpesa;
    }

    /**
     * A QR payment: the SAME till money, plus the QR pair the STK callback
     * writes. Note there is only ever ONE mpesas row — the STK path does not
     * write one, so nothing here double counts.
     */
    private function qrPayment(Vehicle $vehicle, string $transId, float $amount = 80): Mpesa
    {
        $mpesa = $this->tillPayment($vehicle, $transId, $amount);

        $qrcodePayment = QrcodePayment::create([
            'vehicle_id' => $vehicle->id,
            'amount' => $amount,
            'status' => true,
        ]);

        MpesaQrcodePayment::create([
            'transid' => $transId,
            'name' => 'MUNIRA PAYER',
            'amount' => $amount,
            'points' => 0,
            'phone' => '254700111222',
            'transdate' => now(),
            'qrcode_payment_id' => $qrcodePayment->id,
            'callback' => '{}',
            'redeemed' => false,
        ]);

        return $mpesa;
    }

    private function cashPayment(Vehicle $vehicle, string $transId, float $amount = 50): Cash
    {
        $cash = Cash::create([
            'trans_id' => $transId,
            'vehicle_id' => $vehicle->id,
            'firstname' => 'Walk',
            'lastname' => 'In',
            'passengers' => 1,
            'recieved_amount' => $amount,
            'fare_amount' => $amount,
            'luggage_amount' => 0,
            'total_amount' => $amount,
            'change_amount' => 0,
            'trans_date' => now(),
        ]);

        Transaction::create([
            'cash_id' => $cash->id,
            'vehicle_id' => $vehicle->id,
            'amount' => $amount,
            'trans_date' => now(),
        ]);

        return $cash;
    }

    /** @return array<string, string|null> receipt (or cash trans_id) => source */
    private function sourcesByReceipt(array $rows): array
    {
        $sources = [];
        foreach ($rows as $row) {
            $receipt = $row['mpesa']['TransID'] ?? $row['cash']['trans_id'] ?? null;
            $sources[(string) $receipt] = $row['source'] ?? null;
        }

        return $sources;
    }

    #[Test]
    public function a_qr_payment_is_marked_qr_on_the_transactions_listing(): void
    {
        $sacco = $this->makeSacco();
        $vehicle = $this->vehicleFor($sacco, 'KDA001A');
        $this->qrPayment($vehicle, 'QR-RECEIPT-1');
        $this->tillPayment($vehicle, 'TILL-RECEIPT-1');
        $this->cashPayment($vehicle, 'CASH-1');

        Sanctum::actingAs($this->admin($sacco));
        $rows = $this->getJson(self::TRANSACTIONS)->assertOk()->json('transactions');

        $sources = $this->sourcesByReceipt($rows);
        $this->assertSame('qr', $sources['QR-RECEIPT-1'], 'A payment with a matching mpesa_qrcode_payments row is a QR payment.');
        $this->assertSame('mpesa', $sources['TILL-RECEIPT-1'], 'An ordinary till payment keeps the existing rail.');
        $this->assertSame('cash', $sources['CASH-1']);
    }

    #[Test]
    public function a_qr_payment_is_marked_qr_on_the_mpesa_listing(): void
    {
        $sacco = $this->makeSacco();
        $vehicle = $this->vehicleFor($sacco, 'KDA001A');
        $this->qrPayment($vehicle, 'QR-RECEIPT-1');
        $this->tillPayment($vehicle, 'TILL-RECEIPT-1');

        Sanctum::actingAs($this->admin($sacco));
        $rows = $this->getJson(self::MPESA_LISTING)->assertOk()->json('mpesa');

        $sources = array_column($rows, 'source', 'TransID');
        $this->assertSame('qr', $sources['QR-RECEIPT-1']);
        $this->assertSame('mpesa', $sources['TILL-RECEIPT-1']);
    }

    #[Test]
    public function the_source_filter_narrows_the_transactions_listing(): void
    {
        $sacco = $this->makeSacco();
        $vehicle = $this->vehicleFor($sacco, 'KDA001A');
        $this->qrPayment($vehicle, 'QR-RECEIPT-1');
        $this->tillPayment($vehicle, 'TILL-RECEIPT-1');
        $this->cashPayment($vehicle, 'CASH-1');

        Sanctum::actingAs($this->admin($sacco));

        $qrOnly = $this->sourcesByReceipt($this->getJson(self::TRANSACTIONS . '?source=qr')->assertOk()->json('transactions'));
        $this->assertSame(['QR-RECEIPT-1' => 'qr'], $qrOnly);

        // The till rail must EXCLUDE the QR payment even though both are M-Pesa
        // money on the same till — that is the whole point of the marker.
        $tillOnly = $this->sourcesByReceipt($this->getJson(self::TRANSACTIONS . '?source=mpesa')->assertOk()->json('transactions'));
        $this->assertSame(['TILL-RECEIPT-1' => 'mpesa'], $tillOnly);

        $cashOnly = $this->sourcesByReceipt($this->getJson(self::TRANSACTIONS . '?source=cash')->assertOk()->json('transactions'));
        $this->assertSame(['CASH-1' => 'cash'], $cashOnly);
    }

    #[Test]
    public function the_totals_follow_the_source_filter(): void
    {
        $sacco = $this->makeSacco();
        $vehicle = $this->vehicleFor($sacco, 'KDA001A');
        $this->qrPayment($vehicle, 'QR-RECEIPT-1', 30);
        $this->tillPayment($vehicle, 'TILL-RECEIPT-1', 70);
        $this->cashPayment($vehicle, 'CASH-1', 50);

        Sanctum::actingAs($this->admin($sacco));

        $unfiltered = $this->getJson(self::TRANSACTIONS)->assertOk()->json();
        $this->assertSame(100.0, (float) $unfiltered['mpesa'], 'QR money is till money — it is already inside the mpesa total.');
        $this->assertSame(50.0, (float) $unfiltered['cash']);

        // A filtered table under an unfiltered total is a reconciliation dispute.
        $filtered = $this->getJson(self::TRANSACTIONS . '?source=qr')->assertOk()->json();
        $this->assertSame(30.0, (float) $filtered['mpesa']);
        $this->assertSame(0.0, (float) $filtered['cash']);
    }

    #[Test]
    public function the_source_filter_narrows_the_mpesa_listing(): void
    {
        $sacco = $this->makeSacco();
        $vehicle = $this->vehicleFor($sacco, 'KDA001A');
        $this->qrPayment($vehicle, 'QR-RECEIPT-1');
        $this->tillPayment($vehicle, 'TILL-RECEIPT-1');

        Sanctum::actingAs($this->admin($sacco));

        $qrOnly = $this->getJson(self::MPESA_LISTING . '?source=qr')->assertOk()->json('mpesa');
        $this->assertSame(['QR-RECEIPT-1'], array_column($qrOnly, 'TransID'));

        $tillOnly = $this->getJson(self::MPESA_LISTING . '?source=mpesa')->assertOk()->json('mpesa');
        $this->assertSame(['TILL-RECEIPT-1'], array_column($tillOnly, 'TransID'));

        // Nothing on the M-Pesa listing is cash. Narrowing to nothing is honest;
        // handing back every till payment would let an operator book till
        // receipts as cash.
        $this->assertSame([], $this->getJson(self::MPESA_LISTING . '?source=cash')->assertOk()->json('mpesa'));
    }

    #[Test]
    public function the_filter_cannot_widen_past_the_sacco_boundary(): void
    {
        $mine = $this->makeSacco();
        $theirs = $this->makeSacco();
        $this->qrPayment($this->vehicleFor($mine, 'KDA001A'), 'MINE-QR');
        $this->qrPayment($this->vehicleFor($theirs, 'KDB002B'), 'THEIRS-QR');

        Sanctum::actingAs($this->admin($mine));

        $rows = $this->getJson(self::TRANSACTIONS . '?source=qr')->assertOk()->json('transactions');
        $this->assertSame(['MINE-QR' => 'qr'], $this->sourcesByReceipt($rows));

        $mpesaRows = $this->getJson(self::MPESA_LISTING . '?source=qr')->assertOk()->json('mpesa');
        $this->assertSame(['MINE-QR'], array_column($mpesaRows, 'TransID'));
    }

    #[Test]
    public function an_unrecognised_source_is_rejected_rather_than_ignored(): void
    {
        $sacco = $this->makeSacco();
        $this->qrPayment($this->vehicleFor($sacco, 'KDA001A'), 'QR-RECEIPT-1');

        Sanctum::actingAs($this->admin($sacco));

        $this->getJson(self::TRANSACTIONS . '?source=bank')->assertStatus(400);
        $this->getJson(self::MPESA_LISTING . '?source=bank')->assertStatus(400);

        // An array (?source[]=qr) is not "no filter asked for" either — it must
        // not fall through to an unfiltered page of every rail.
        $this->getJson(self::TRANSACTIONS . '?source[]=qr')->assertStatus(400);

        // Case is not a reason to silently drop the filter.
        $this->assertSame(
            ['QR-RECEIPT-1' => 'qr'],
            $this->sourcesByReceipt($this->getJson(self::TRANSACTIONS . '?source=QR')->assertOk()->json('transactions'))
        );

        // An empty source is "no filter", i.e. exactly the old behaviour.
        $this->getJson(self::TRANSACTIONS . '?source=')->assertOk();
    }

    #[Test]
    public function the_transactions_listing_resolves_a_whole_page_in_one_query(): void
    {
        $sacco = $this->makeSacco();
        $vehicle = $this->vehicleFor($sacco, 'KDA001A');
        $this->qrPayment($vehicle, 'QR-1');
        $this->tillPayment($vehicle, 'TILL-1');

        Sanctum::actingAs($this->admin($sacco));

        // Warm-up, deliberately unmeasured: the first request of a test also
        // fills the permission cache, so counting it would compare a cold run
        // against a warm one and the row count would not be what moved.
        $this->getJson(self::TRANSACTIONS)->assertOk();

        $smallPage = $this->countQueries(fn () => $this->getJson(self::TRANSACTIONS)->assertOk());

        // Ten more rows on the same page. If the source were resolved per row,
        // this number would climb with it — at 20 rows a page over 1.3M
        // transactions that is 20 extra round trips on every click of "next".
        for ($i = 2; $i <= 6; $i++) {
            $this->qrPayment($vehicle, "QR-{$i}");
            $this->tillPayment($vehicle, "TILL-{$i}");
        }

        $fullPage = $this->countQueries(fn () => $this->getJson(self::TRANSACTIONS)->assertOk());

        $this->assertSame(1, $smallPage['qr']);
        $this->assertSame(1, $fullPage['qr'], 'The whole page\'s QR receipts must be resolved in ONE whereIn.');
        $this->assertLessThanOrEqual(
            $smallPage['total'],
            $fullPage['total'],
            'Six times the rows must not cost a single extra query anywhere on this path.'
        );
    }

    #[Test]
    public function the_mpesa_listing_resolves_a_whole_page_in_one_query(): void
    {
        $sacco = $this->makeSacco();
        $vehicle = $this->vehicleFor($sacco, 'KDA001A');
        $this->qrPayment($vehicle, 'QR-1');

        Sanctum::actingAs($this->admin($sacco));

        $this->getJson(self::MPESA_LISTING)->assertOk();   // warm-up, see above
        $smallPage = $this->countQueries(fn () => $this->getJson(self::MPESA_LISTING)->assertOk());

        for ($i = 2; $i <= 6; $i++) {
            $this->qrPayment($vehicle, "QR-{$i}");
            $this->tillPayment($vehicle, "TILL-{$i}");
        }

        $fullPage = $this->countQueries(fn () => $this->getJson(self::MPESA_LISTING)->assertOk());

        $this->assertSame(1, $smallPage['qr']);
        $this->assertSame(1, $fullPage['qr']);
        $this->assertLessThanOrEqual($smallPage['total'], $fullPage['total']);
    }

    #[Test]
    public function the_existing_response_keys_are_unchanged(): void
    {
        $sacco = $this->makeSacco();
        $vehicle = $this->vehicleFor($sacco, 'KDA001A');
        $this->qrPayment($vehicle, 'QR-RECEIPT-1', 30);
        $this->cashPayment($vehicle, 'CASH-1', 50);

        Sanctum::actingAs($this->admin($sacco));

        // The dashboard renders against these; `source` is additive beside them.
        $this->getJson(self::TRANSACTIONS)->assertOk()->assertJsonStructure([
            'mpesa',
            'cash',
            'transactions' => [
                '*' => ['id', 'vehicle_id', 'cash_id', 'mpesa_id', 'amount', 'trans_date', 'vehicle', 'source'],
            ],
        ]);

        $this->getJson(self::MPESA_LISTING)->assertOk()->assertJsonStructure([
            'mpesa' => [
                '*' => ['id', 'TransID', 'MSISDN', 'TransAmount', 'TransTime', 'FirstName', 'LastName',
                    'BusinessShortCode', 'BillRefNumber', 'transaction', 'source'],
            ],
        ]);
    }

    /**
     * Run $call and count the queries it issued.
     *
     * `qr` counts only the statements that touch mpesa_qrcode_payments, which is
     * what an N+1 on this path would multiply.
     *
     * @return array{total: int, qr: int}
     */
    private function countQueries(callable $call): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        try {
            $call();
            $log = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
        }

        $qr = 0;
        foreach ($log as $entry) {
            if (str_contains((string) $entry['query'], 'mpesa_qrcode_payments')) {
                $qr++;
            }
        }

        return ['total' => count($log), 'qr' => $qr];
    }
}
