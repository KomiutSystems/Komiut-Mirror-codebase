<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use App\Enums\UserType;
use App\Models\Mpesa;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Vehicle;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Queues\QueueTestCase;

/**
 * Exporting a transaction listing.
 *
 * WHY IT EXISTS. The dashboard could already export SUMMARIES — one row per bus
 * per day — but there was no way to download the individual payments behind
 * them. That is the wrong grain for the thing people actually do with it:
 * reconciling one matatu's takings for one day against the conductor's book.
 * Trying to export "today's transactions for this vehicle" hit an endpoint that
 * did not exist.
 *
 * THE PROPERTY THAT MATTERS MOST here is not the file format. It is that the
 * download and the screen agree: both read the same builder, so a filter can
 * never apply to the table but not the CSV. A spreadsheet that quietly disagrees
 * with the page it came from is what somebody reconciles against and then cannot
 * explain.
 */
final class TransactionsExportTest extends QueueTestCase
{
    private const ENDPOINT = '/api/v1/auth/transactions/export';

    private function admin(array $world): User
    {
        $u = $this->makeUser(['View Transactions'], $world['sacco']);
        $u->forceFill(['type' => UserType::Admin, 'sacco_id' => $world['sacco']->id])->save();

        return $u->fresh();
    }

    private function payment(Vehicle $vehicle, float $amount, string $receipt, ?string $when = null): Transaction
    {
        $at = $when ?? now()->toDateTimeString();

        $mpesa = Mpesa::withoutGlobalScopes()->create([
            'TransID' => $receipt,
            'TransAmount' => (string) $amount,
            'TransTime' => $at,
            'MSISDN' => '254712345678',
            'FirstName' => 'Joyce',
            'LastName' => 'Wanjiru',
            'BusinessShortCode' => '7100466',
        ]);

        return Transaction::withoutGlobalScopes()->create([
            'vehicle_id' => $vehicle->id,
            'mpesa_id' => $mpesa->id,
            'amount' => $amount,
            'trans_date' => $at,
        ]);
    }

    #[Test]
    public function a_days_payments_for_one_vehicle_download_as_csv(): void
    {
        // The exact thing that errored: today, this bus.
        $world = $this->makeWorld();
        $other = $this->makeVehicle($world['sacco'], $world['owner'], $world['seat']);

        $this->payment($world['vehicle'], 150, 'UHQ434J0C3');
        $this->payment($other, 999, 'OTHERBUS1');

        Sanctum::actingAs($this->admin($world));

        $res = $this->get(self::ENDPOINT.'?format=csv&date='.now()->toDateString().'&vehicles='.$world['vehicle']->id);
        $res->assertOk();
        $res->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $csv = $res->streamedContent();

        $this->assertStringContainsString('UHQ434J0C3', $csv);
        $this->assertStringNotContainsString('OTHERBUS1', $csv, 'the vehicle filter must apply to the download too');
        $this->assertStringContainsString('Reference', $csv, 'the header row');
    }

    #[Test]
    public function the_csv_carries_the_receipt_payer_and_plate(): void
    {
        // The columns that make a CSV reconcilable. Without the M-Pesa receipt
        // there is nothing to match against a statement.
        $world = $this->makeWorld();
        $this->payment($world['vehicle'], 150, 'UHQ434J0C3');

        Sanctum::actingAs($this->admin($world));

        $csv = $this->get(self::ENDPOINT.'?date='.now()->toDateString())->assertOk()->streamedContent();

        $this->assertStringContainsString('UHQ434J0C3', $csv);
        $this->assertStringContainsString('Joyce Wanjiru', $csv);
        $this->assertStringContainsString($world['vehicle']->plate, $csv);
        $this->assertStringContainsString('M-Pesa', $csv);
    }

    #[Test]
    public function the_footer_totals_what_the_rows_add_up_to(): void
    {
        $world = $this->makeWorld();
        $this->payment($world['vehicle'], 150, 'TX1');
        $this->payment($world['vehicle'], 50, 'TX2');

        Sanctum::actingAs($this->admin($world));

        $csv = $this->get(self::ENDPOINT.'?date='.now()->toDateString())->assertOk()->streamedContent();

        $this->assertStringContainsString('TOTAL', $csv);
        $this->assertStringContainsString('200.00', $csv);
    }

    #[Test]
    public function a_download_over_the_cap_is_refused_with_the_count_never_cut_short(): void
    {
        // NCBA's one-day export on 2026-09-16 carried 20,000 of 27,671 payments
        // and a TOTAL under them, with nothing to say the file was short.
        config(['platform.exports.csv_max_rows' => 3]);
        $world = $this->makeWorld();
        foreach (['TX1', 'TX2', 'TX3', 'TX4'] as $i => $r) {
            $this->payment($world['vehicle'], 10, $r);
        }
        Sanctum::actingAs($this->admin($world));

        $this->getJson(self::ENDPOINT.'?date='.now()->toDateString())
            ->assertStatus(422)
            ->assertJsonPath('rows', 4)
            ->assertJsonPath('limit', 3)
            ->assertJsonPath('error', 'This export has 4 payments; a CSV download holds at most 3. Narrow the date range or filter by vehicle.');

        // Narrowed to fit, every row and the true total.
        config(['platform.exports.csv_max_rows' => 4]);
        $csv = $this->get(self::ENDPOINT.'?date='.now()->toDateString())->assertOk()->streamedContent();
        foreach (['TX1', 'TX2', 'TX3', 'TX4'] as $r) {
            $this->assertStringContainsString($r, $csv);
        }
        $this->assertStringContainsString('4 txn(s),40.00', $csv);
    }

    #[Test]
    public function the_pdf_has_its_own_smaller_cap(): void
    {
        config(['platform.exports.csv_max_rows' => 100, 'platform.exports.pdf_max_rows' => 1]);
        $world = $this->makeWorld();
        $this->payment($world['vehicle'], 10, 'TX1');
        $this->payment($world['vehicle'], 10, 'TX2');
        Sanctum::actingAs($this->admin($world));

        $this->getJson(self::ENDPOINT.'?format=pdf&date='.now()->toDateString())->assertStatus(422)->assertJsonPath('limit', 1);
        $this->get(self::ENDPOINT.'?format=csv&date='.now()->toDateString())->assertOk();
    }

    #[Test]
    public function another_saccos_payments_are_never_in_the_download(): void
    {
        // The export is unpaginated, which makes a missing tenant boundary far
        // more damaging here than on a 20-row page.
        $mine = $this->makeWorld();
        $theirs = $this->makeWorld();

        $this->payment($mine['vehicle'], 150, 'MINE01');
        $this->payment($theirs['vehicle'], 150, 'THEIRS1');

        Sanctum::actingAs($this->admin($mine));

        $csv = $this->get(self::ENDPOINT.'?date='.now()->toDateString())->assertOk()->streamedContent();

        $this->assertStringContainsString('MINE01', $csv);
        $this->assertStringNotContainsString('THEIRS1', $csv);
    }

    #[Test]
    public function the_export_needs_the_same_permission_as_the_screen(): void
    {
        $world = $this->makeWorld();
        $nobody = $this->makeUser([], $world['sacco']);
        $nobody->forceFill(['sacco_id' => $world['sacco']->id])->save();

        Sanctum::actingAs($nobody->fresh());

        $this->get(self::ENDPOINT.'?date='.now()->toDateString())->assertStatus(403);
    }

    #[Test]
    public function an_unknown_format_is_refused_rather_than_guessed(): void
    {
        $world = $this->makeWorld();
        Sanctum::actingAs($this->admin($world));

        $this->getJson(self::ENDPOINT.'?format=xlsx')
            ->assertStatus(400)
            ->assertJsonPath('error', 'format must be csv or pdf');
    }

    #[Test]
    public function a_date_range_exports_more_than_a_single_day(): void
    {
        $world = $this->makeWorld();
        $this->payment($world['vehicle'], 150, 'TODAY01');
        $this->payment($world['vehicle'], 150, 'BACKTHEN', now()->subDays(3)->toDateTimeString());

        Sanctum::actingAs($this->admin($world));

        $oneDay = $this->get(self::ENDPOINT.'?date='.now()->toDateString())->assertOk()->streamedContent();
        $this->assertStringNotContainsString('BACKTHEN', $oneDay);

        $range = $this->get(self::ENDPOINT.'?from='.now()->subDays(4)->toDateString().'&to='.now()->toDateString())
            ->assertOk()->streamedContent();

        $this->assertStringContainsString('BACKTHEN', $range);
        $this->assertStringContainsString('TODAY01', $range);
    }

    #[Test]
    public function the_listing_says_which_days_it_actually_covered(): void
    {
        // NICCO's dashboard rendered "This server filters by a single day, so
        // only 2026-08-29 is shown" above a table of 30 August rows and a
        // correct two-day total of KES 33,530. The money was right; the caption
        // was a guess, because the response never said what range it applied.
        // `to` is the last INCLUDED day so a client can print it as-is.
        $world = $this->makeWorld();
        $this->payment($world['vehicle'], 150, 'TODAY01');
        $this->payment($world['vehicle'], 150, 'BACKTHEN', now()->subDays(1)->toDateTimeString());

        Sanctum::actingAs($this->admin($world));

        $body = $this->getJson(
            '/api/v1/auth/transactions?from='.now()->subDay()->toDateString().'&to='.now()->toDateString()
        )->assertOk()->json();

        $this->assertSame(now()->subDay()->toDateString(), $body['range']['from']);
        $this->assertSame(now()->toDateString(), $body['range']['to'], 'the last day INCLUDED');
        $this->assertCount(2, $body['transactions'], 'and both days are genuinely there');
    }

    #[Test]
    public function a_single_day_reports_itself_as_one_day(): void
    {
        $world = $this->makeWorld();
        $this->payment($world['vehicle'], 150, 'TODAY01');

        Sanctum::actingAs($this->admin($world));

        $body = $this->getJson('/api/v1/auth/transactions?date='.now()->toDateString())
            ->assertOk()->json();

        $this->assertSame(now()->toDateString(), $body['range']['from']);
        $this->assertSame(now()->toDateString(), $body['range']['to']);
    }

    #[Test]
    public function the_screen_and_the_download_read_the_same_filters(): void
    {
        // One builder feeds both. A filter that narrowed the table but not the
        // CSV would produce a download that disagrees with the page it came
        // from — and nobody would know which one was right.
        $world = $this->makeWorld();
        $other = $this->makeVehicle($world['sacco'], $world['owner'], $world['seat']);

        $this->payment($world['vehicle'], 150, 'WANTED01');
        $this->payment($other, 150, 'NOTWANTED');

        Sanctum::actingAs($this->admin($world));

        $query = 'date='.now()->toDateString().'&vehicles='.$world['vehicle']->id;

        $onScreen = $this->getJson('/api/v1/auth/transactions?'.$query)->assertOk()->json('transactions');
        $csv = $this->get(self::ENDPOINT.'?'.$query)->assertOk()->streamedContent();

        $this->assertCount(1, $onScreen);
        $this->assertStringContainsString('WANTED01', $csv);
        $this->assertStringNotContainsString('NOTWANTED', $csv);
    }
}
