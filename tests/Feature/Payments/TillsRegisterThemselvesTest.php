<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use App\Jobs\RegisterVehicleTill;
use App\Models\Mpesa;
use App\Models\MpesaPaymentSetting;
use App\Models\Sacco;
use App\Models\Vehicle;
use App\Services\Mpesa\TillRegistrar;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Queues\QueueTestCase;

/**
 * A bus saved with a till registers itself with Safaricom.
 *
 * NICCO added KEA 742C, 743C and 744C on 2026-11-06. Nobody pressed "Register
 * till", so Safaricom delivered their payments to nobody for 19 days, until
 * the SACCO emailed to ask why the buses were "not reflecting".
 */
final class TillsRegisterThemselvesTest extends QueueTestCase
{
    private function connection(Sacco $sacco, string $headOffice): MpesaPaymentSetting
    {
        return MpesaPaymentSetting::create([
            'sacco_id' => $sacco->id, 'consumer_key' => 'ck-'.$headOffice, 'consumer_secret' => 'cs-'.$headOffice,
            'business_short_code' => $headOffice, 'pass_key' => 'pk', 'payment_mode' => 'CustomerBuyGoodsOnline',
            'is_live' => true, 'status' => true,
        ]);
    }

    /** @param  array<string, mixed>|null  $answer  what c2b/v2/registerurl says */
    private function safaricom(?array $answer = null, int $status = 200): void
    {
        Http::fake([
            '*/oauth/v1/generate*' => Http::response(['access_token' => 'tok', 'expires_in' => '3599']),
            '*/mpesa/c2b/v2/registerurl' => Http::response($answer ?? [
                'OriginatorCoversationID' => '1786-460f', 'ResponseCode' => '00000000', 'ResponseDescription' => 'Success',
            ], $status),
        ]);
    }

    private function runQueuedRegistration(Vehicle $vehicle): void
    {
        (new RegisterVehicleTill((int) $vehicle->id))->handle(app(TillRegistrar::class));
    }

    #[Test]
    public function a_new_till_in_a_sacco_with_one_head_office_registers_itself(): void
    {
        $this->safaricom();
        $world = $this->makeWorld();
        $connection = $this->connection($world['sacco'], '5495094');

        $world['vehicle']->forceFill(['merchant_short_code' => '1417368', 'till_number' => '1885147'])->save();
        $bus = $world['vehicle']->fresh();

        $this->assertSame(TillRegistrar::PENDING, $bus->till_registration_status);
        Bus::assertDispatched(RegisterVehicleTill::class, fn (RegisterVehicleTill $job) => $job->vehicleId === $bus->id);

        $this->runQueuedRegistration($bus);

        $bus->refresh();
        $this->assertSame(TillRegistrar::REGISTERED, $bus->till_registration_status);
        $this->assertNotNull($bus->till_registered_at);
        $this->assertStringEndsWith('/api/confirmation/'.$connection->id, (string) $bus->till_registered_url);
        $this->assertSame($connection->id, (int) $bus->mpesa_payment_setting_id, 'linked to the head office that registered it');
        Http::assertSent(fn (ClientRequest $r) => str_contains($r->url(), 'registerurl') && $r['ShortCode'] === '1417368');
    }

    #[Test]
    public function with_several_head_offices_it_asks_which_rather_than_guessing(): void
    {
        // Safaricom keeps a till's first registration: a wrong guess is permanent.
        Http::fake();
        $world = $this->makeWorld();
        $this->connection($world['sacco'], '7071220')->forceFill(['is_default' => true])->save();
        $this->connection($world['sacco'], '5495094');

        $world['vehicle']->forceFill(['merchant_short_code' => '1417368'])->save();

        $bus = $world['vehicle']->fresh();
        $this->assertSame(TillRegistrar::NEEDS_HEAD_OFFICE, $bus->till_registration_status);
        $this->assertStringContainsString('head office', (string) $bus->till_registration_note);
        Bus::assertNotDispatched(RegisterVehicleTill::class);
        Http::assertNothingSent();
    }

    #[Test]
    public function choosing_the_head_office_registers_it_with_that_head_offices_keys(): void
    {
        $this->safaricom();
        $world = $this->makeWorld();
        $this->connection($world['sacco'], '7071220');
        $chosen = $this->connection($world['sacco'], '5495094');
        $world['vehicle']->forceFill(['merchant_short_code' => '1417368'])->save();

        $world['vehicle']->forceFill(['mpesa_payment_setting_id' => $chosen->id])->save();
        Bus::assertDispatched(RegisterVehicleTill::class);
        $this->runQueuedRegistration($world['vehicle']);

        $this->assertSame(TillRegistrar::REGISTERED, $world['vehicle']->fresh()->till_registration_status);
        Http::assertSent(fn (ClientRequest $r) => str_contains($r->url(), 'oauth')
            && $r->hasHeader('Authorization', 'Basic '.base64_encode('ck-5495094:cs-5495094')));
        Http::assertSent(fn (ClientRequest $r) => str_contains($r->url(), 'registerurl')
            && str_ends_with((string) $r['ConfirmationURL'], '/api/confirmation/'.$chosen->id));
    }

    #[Test]
    public function already_registered_and_collecting_here_is_registered(): void
    {
        $this->safaricom(['requestId' => 'x', 'errorCode' => '500.003.1001', 'errorMessage' => 'URLs are already registered'], 500);
        $world = $this->makeWorld();
        $this->connection($world['sacco'], '5495094');
        Mpesa::withoutGlobalScopes()->create(['TransID' => 'UKA1B2C3D4', 'MSISDN' => '254700000001', 'TransAmount' => 50,
            'TransTime' => now(), 'FirstName' => 'A', 'LastName' => 'B', 'BusinessShortCode' => '1417368', 'BillRefNumber' => '',
        ])->forceFill(['mpesa_setting_id' => 16])->save();
        $world['vehicle']->forceFill(['merchant_short_code' => '1417368'])->save();

        $this->runQueuedRegistration($world['vehicle']);

        $this->assertSame(TillRegistrar::REGISTERED, $world['vehicle']->fresh()->till_registration_status);
    }

    #[Test]
    public function already_registered_with_nothing_arriving_says_so_without_accusing_anyone(): void
    {
        $this->safaricom(['requestId' => 'x', 'errorCode' => '500.003.1001', 'errorMessage' => 'URLs are already registered'], 500);
        $world = $this->makeWorld();
        $this->connection($world['sacco'], '5495094');
        $world['vehicle']->forceFill(['merchant_short_code' => '1417368'])->save();

        $this->runQueuedRegistration($world['vehicle']);

        $bus = $world['vehicle']->fresh();
        $this->assertSame(TillRegistrar::ALREADY_REGISTERED, $bus->till_registration_status);
        $this->assertNull($bus->till_registered_at, 'nothing was registered here');
    }

    #[Test]
    public function an_unreachable_safaricom_is_tried_again_later(): void
    {
        Http::fake(['*/oauth/v1/generate*' => Http::response('down', 503)]);
        $world = $this->makeWorld();
        $this->connection($world['sacco'], '5495094');
        $world['vehicle']->forceFill(['merchant_short_code' => '1417368'])->save();

        $job = (new RegisterVehicleTill((int) $world['vehicle']->id))->withFakeQueueInteractions();
        $job->handle(app(TillRegistrar::class));

        $job->assertReleased(60);
        $this->assertSame(TillRegistrar::UNREACHABLE, $world['vehicle']->fresh()->till_registration_status);
        $this->assertStringContainsString('keep trying', (string) $world['vehicle']->fresh()->till_registration_note);
    }

    #[Test]
    public function a_dropped_connection_is_unreachable_too_not_seven_instant_failures(): void
    {
        Http::fake(['*/oauth/v1/generate*' => fn () => throw new ConnectionException('Connection timed out')]);
        $world = $this->makeWorld();
        $this->connection($world['sacco'], '5495094');
        $world['vehicle']->forceFill(['merchant_short_code' => '1417368'])->save();

        $job = (new RegisterVehicleTill((int) $world['vehicle']->id))->withFakeQueueInteractions();
        $job->handle(app(TillRegistrar::class));

        $job->assertReleased(60);
    }

    #[Test]
    public function when_the_retries_run_out_the_bus_says_to_press_register_till(): void
    {
        Http::fake(['*/oauth/v1/generate*' => Http::response('down', 503)]);
        $world = $this->makeWorld();
        $this->connection($world['sacco'], '5495094');
        $world['vehicle']->forceFill(['merchant_short_code' => '1417368'])->save();

        $job = (new RegisterVehicleTill((int) $world['vehicle']->id))->withFakeQueueInteractions();
        $job->job->attempts = count(RegisterVehicleTill::RETRY_MINUTES) + 1;
        $job->handle(app(TillRegistrar::class));

        $job->assertNotReleased();
        $this->assertSame(TillRegistrar::GAVE_UP, $world['vehicle']->fresh()->till_registration_status);
    }

    #[Test]
    public function rejected_keys_are_named_and_not_retried(): void
    {
        Http::fake(['*/oauth/v1/generate*' => Http::response(['errorMessage' => 'Invalid Authentication passed'], 400)]);
        $world = $this->makeWorld();
        $this->connection($world['sacco'], '5495094');
        $world['vehicle']->forceFill(['merchant_short_code' => '1417368'])->save();

        $job = (new RegisterVehicleTill((int) $world['vehicle']->id))->withFakeQueueInteractions();
        $job->handle(app(TillRegistrar::class));

        $job->assertNotReleased();
        $this->assertSame(TillRegistrar::BAD_KEYS, $world['vehicle']->fresh()->till_registration_status);
    }

    #[Test]
    public function a_new_till_number_is_registered_even_though_the_old_one_was(): void
    {
        $this->safaricom();
        $world = $this->makeWorld();
        $this->connection($world['sacco'], '5495094');
        $world['vehicle']->forceFill(['merchant_short_code' => '1417368'])->save();
        $this->runQueuedRegistration($world['vehicle']);

        $world['vehicle']->fresh()->forceFill(['merchant_short_code' => '1417999'])->save();

        $this->assertSame(TillRegistrar::PENDING, $world['vehicle']->fresh()->till_registration_status);
        $this->runQueuedRegistration($world['vehicle']);
        $bus = $world['vehicle']->fresh();
        $this->assertSame(TillRegistrar::REGISTERED, $bus->till_registration_status);
        $this->assertSame('1417999', $bus->till_registered_short_code);
        Http::assertSent(fn (ClientRequest $r) => str_contains($r->url(), 'registerurl') && $r['ShortCode'] === '1417999');
    }

    #[Test]
    public function a_bus_registered_here_before_is_not_asked_for_a_head_office(): void
    {
        // Registered through the dashboard before buses were linked: the URL
        // names the head office. Several connections, no link -- still known.
        $world = $this->makeWorld();
        $this->connection($world['sacco'], '7071220');
        $under = $this->connection($world['sacco'], '3020809');
        $world['vehicle']->forceFill([
            'merchant_short_code' => '1277149', 'till_registered_at' => now(),
            'till_registered_url' => rtrim((string) config('app.url'), '/').'/api/confirmation/'.$under->id,
            'till_registered_short_code' => '1277149',
        ])->saveQuietly();

        $this->assertSame(TillRegistrar::REGISTERED, app(TillRegistrar::class)->precheck($world['vehicle']->fresh()));
    }

    #[Test]
    public function a_test_system_head_office_is_never_used_for_a_live_till(): void
    {
        Http::fake();
        $world = $this->makeWorld();
        $sandbox = $this->connection($world['sacco'], '174379');
        $sandbox->forceFill(['is_live' => false])->save();
        $world['vehicle']->forceFill(['merchant_short_code' => '1417368', 'mpesa_payment_setting_id' => $sandbox->id])->save();

        $this->assertSame(TillRegistrar::NOT_LIVE, $world['vehicle']->fresh()->till_registration_status);
        Http::assertNothingSent();
    }

    #[Test]
    public function a_bank_issued_till_or_a_bank_account_on_record_is_left_to_the_bank(): void
    {
        Http::fake();
        $world = $this->makeWorld();
        $this->connection($world['sacco'], '5495094');
        $world['vehicle']->forceFill(['till_number' => '7654321', 'merchant_short_code' => '7654321',
            'till_registration_status' => TillRegistrar::BANK_ISSUED])->save();
        $other = $this->makeVehicle($world['sacco'], $world['owner'], $world['seat']);
        $other->forceFill(['merchant_short_code' => '1123852', 'coop_till' => '01101233137021'])->save();

        $this->assertSame(TillRegistrar::BANK_ISSUED, $world['vehicle']->fresh()->till_registration_status);
        $this->assertNull($other->fresh()->till_registration_status);
        Bus::assertNotDispatched(RegisterVehicleTill::class);
        Http::assertNothingSent();
    }

    #[Test]
    public function a_pulled_or_imported_payment_is_not_proof_the_till_delivers_here(): void
    {
        // Pulled and imported rows carry no confirmation URL id: they say
        // nothing about where Safaricom delivers this till.
        $this->safaricom(['requestId' => 'x', 'errorCode' => '500.003.1001', 'errorMessage' => 'URLs are already registered'], 500);
        $world = $this->makeWorld();
        $this->connection($world['sacco'], '5495094');
        Mpesa::withoutGlobalScopes()->create(['TransID' => 'UKPULL0001', 'MSISDN' => '254700000001', 'TransAmount' => 50,
            'TransTime' => now(), 'FirstName' => 'A', 'LastName' => 'B', 'BusinessShortCode' => '1417368', 'BillRefNumber' => '']);
        $world['vehicle']->forceFill(['merchant_short_code' => '1417368'])->save();

        $this->runQueuedRegistration($world['vehicle']);

        $this->assertSame(TillRegistrar::ALREADY_REGISTERED, $world['vehicle']->fresh()->till_registration_status);
    }

    #[Test]
    public function a_changed_till_waits_for_its_head_office_to_be_confirmed_when_there_are_several(): void
    {
        Http::fake();
        $world = $this->makeWorld();
        $old = $this->connection($world['sacco'], '5495094');
        $this->connection($world['sacco'], '3020809');
        $world['vehicle']->forceFill(['merchant_short_code' => '1417368', 'mpesa_payment_setting_id' => $old->id])->save();
        $dispatched = count(Bus::dispatched(RegisterVehicleTill::class));

        $world['vehicle']->fresh()->forceFill(['merchant_short_code' => '1467196'])->save();

        $this->assertSame(TillRegistrar::CONFIRM_HEAD_OFFICE, $world['vehicle']->fresh()->till_registration_status);
        $this->assertCount($dispatched, Bus::dispatched(RegisterVehicleTill::class));
        Http::assertNothingSent();
    }

    #[Test]
    public function a_bank_issued_till_is_never_sent_to_safaricom_even_on_a_linked_bus(): void
    {
        Http::fake();
        $world = $this->makeWorld();
        $linked = $this->connection($world['sacco'], '5495094');
        $world['vehicle']->forceFill(['merchant_short_code' => '1417368', 'mpesa_payment_setting_id' => $linked->id])->save();
        $dispatched = count(Bus::dispatched(RegisterVehicleTill::class));

        // What TillRequestsController::apply writes.
        $world['vehicle']->fresh()->forceFill(['till_number' => '7654321', 'merchant_short_code' => '7654321',
            'till_registration_status' => TillRegistrar::BANK_ISSUED])->save();

        $this->assertSame(TillRegistrar::BANK_ISSUED, $world['vehicle']->fresh()->till_registration_status);
        $this->assertCount($dispatched, Bus::dispatched(RegisterVehicleTill::class));
        $this->assertFalse(app(TillRegistrar::class)->isOurs($world['vehicle']->fresh()));
    }

    #[Test]
    public function a_till_changed_while_safaricom_was_being_asked_is_asked_about_again(): void
    {
        $world = $this->makeWorld();
        $this->connection($world['sacco'], '5495094');
        $world['vehicle']->forceFill(['merchant_short_code' => '1417368'])->save();
        $id = $world['vehicle']->id;
        Http::fake([
            '*/oauth/v1/generate*' => Http::response(['access_token' => 'tok', 'expires_in' => '3599']),
            '*/mpesa/c2b/v2/registerurl' => function () use ($id) {
                // The SACCO corrects the till while the call is in flight.
                Vehicle::withoutGlobalScopes()->whereKey($id)->update(['merchant_short_code' => '1417999']);

                return Http::response(['ResponseCode' => '00000000', 'ResponseDescription' => 'Success']);
            },
        ]);

        $job = (new RegisterVehicleTill((int) $id))->withFakeQueueInteractions();
        $job->handle(app(TillRegistrar::class));

        $job->assertReleased(5);
        $this->assertNotSame(TillRegistrar::REGISTERED, $world['vehicle']->fresh()->till_registration_status);
        $this->assertNull($world['vehicle']->fresh()->till_registered_short_code);
    }

    #[Test]
    public function too_many_token_requests_is_retried_not_called_bad_keys(): void
    {
        Http::fake(['*/oauth/v1/generate*' => Http::response(['errorMessage' => 'Spike arrest violation'], 429)]);
        $world = $this->makeWorld();
        $this->connection($world['sacco'], '5495094');
        $world['vehicle']->forceFill(['merchant_short_code' => '1417368'])->save();

        $job = (new RegisterVehicleTill((int) $world['vehicle']->id))->withFakeQueueInteractions();
        $job->handle(app(TillRegistrar::class));

        $job->assertReleased(60);
        $this->assertSame(TillRegistrar::UNREACHABLE, $world['vehicle']->fresh()->till_registration_status);
    }

    #[Test]
    public function a_failure_here_never_breaks_the_transaction_it_happens_in(): void
    {
        $world = $this->makeWorld();
        $this->app->bind(TillRegistrar::class, fn () => throw new \RuntimeException('registrar down'));

        DB::transaction(function () use ($world) {
            $world['vehicle']->forceFill(['merchant_short_code' => '1417368'])->save();
            // The caller's next statement must still run.
            $world['vehicle']->forceFill(['fleet_no' => 'M744'])->save();
        });

        $this->assertSame('M744', $world['vehicle']->fresh()->fleet_no);
    }

    #[Test]
    public function the_cross_sacco_plate_check_still_sees_what_changed(): void
    {
        // The status write must not reset the change set the next observer reads.
        $world = $this->makeWorld();
        $this->connection($world['sacco'], '5495094');
        $world['vehicle']->forceFill(['merchant_short_code' => '1417368', 'plate' => 'KEA 744C'])->save();

        $this->assertTrue($world['vehicle']->wasChanged('plate'));
        $this->assertTrue($world['vehicle']->wasChanged('merchant_short_code'));
    }

    #[Test]
    public function the_button_tells_a_bank_delivered_bus_why_not_to_add_keys(): void
    {
        Http::fake();
        $world = $this->makeWorld();
        $this->connection($world['sacco'], '5495094');
        $world['vehicle']->forceFill(['merchant_short_code' => '1123852', 'coop_till' => '01101233137021'])->save();
        Sanctum::actingAs($this->makeUser(['Edit Payment Settings'], $world['sacco']));

        $this->postJson('/api/v1/auth/mpesa/tills/'.$world['vehicle']->id.'/register')
            ->assertStatus(422)->assertJsonPath('reason', TillRegistrar::NOT_OURS);
        Http::assertNothingSent();
    }

    #[Test]
    public function a_failure_here_never_breaks_saving_the_bus(): void
    {
        $world = $this->makeWorld();
        $this->app->bind(TillRegistrar::class, fn () => throw new \RuntimeException('registrar down'));

        $world['vehicle']->forceFill(['merchant_short_code' => '1417368'])->save();

        $this->assertSame('1417368', (string) $world['vehicle']->fresh()->merchant_short_code);
    }

    #[Test]
    public function a_refusal_keeps_safaricoms_reason_on_the_bus(): void
    {
        $this->safaricom(['requestId' => 'x', 'errorCode' => '400.003.02', 'errorMessage' => 'Bad Request - Invalid ShortCode'], 400);
        $world = $this->makeWorld();
        $this->connection($world['sacco'], '5495094');
        $world['vehicle']->forceFill(['merchant_short_code' => '1417368'])->save();

        $this->runQueuedRegistration($world['vehicle']);

        $bus = $world['vehicle']->fresh();
        $this->assertSame(TillRegistrar::REFUSED, $bus->till_registration_status);
        $this->assertSame('Safaricom: Bad Request - Invalid ShortCode', $bus->till_registration_note);
        $this->assertNull($bus->till_registered_at);
    }

    #[Test]
    public function bank_delivered_buses_are_left_alone(): void
    {
        Http::fake();
        // NCBA's aggregator paybill, in a SACCO that has a connection.
        $ncba = $this->makeWorld();
        $this->connection($ncba['sacco'], '5495094');
        $ncba['vehicle']->forceFill(['merchant_short_code' => '880100', 'till_number' => '299106'])->save();
        // A Co-op till, in a SACCO with no M-Pesa connection of its own.
        $coop = $this->makeWorld();
        $coop['vehicle']->forceFill(['merchant_short_code' => '1123852'])->save();

        $this->assertNull($ncba['vehicle']->fresh()->till_registration_status);
        $this->assertNull($coop['vehicle']->fresh()->till_registration_status);
        Bus::assertNotDispatched(RegisterVehicleTill::class);
        Http::assertNothingSent();
    }

    #[Test]
    public function editing_something_else_does_not_register_again(): void
    {
        $world = $this->makeWorld();
        $this->connection($world['sacco'], '5495094');
        $world['vehicle']->forceFill(['merchant_short_code' => '1417368'])->save();
        Bus::assertDispatchedTimes(RegisterVehicleTill::class, 1);

        $world['vehicle']->forceFill(['fleet_no' => 'M744'])->save();

        Bus::assertDispatchedTimes(RegisterVehicleTill::class, 1);
    }

    #[Test]
    public function the_person_adding_the_bus_is_told_where_its_till_stands(): void
    {
        $world = $this->makeWorld();
        $this->connection($world['sacco'], '7071220');
        $this->connection($world['sacco'], '5495094');
        $admin = $this->makeUser(['Add Vehicles'], $world['sacco']);
        Sanctum::actingAs($admin);

        $this->postJson('/api/v1/auth/vehicles/add', [
            'id' => 0, 'plate' => 'KEA 742C', 'seat' => $world['seat']->name, 'status' => 1,
            'merchant_short_code' => 1417369, 'till_number' => 1885148,
        ])
            ->assertOk()
            ->assertJsonPath('success', 'Vehicle saved successfully')
            ->assertJsonPath('till_registration.status', TillRegistrar::NEEDS_HEAD_OFFICE);
    }
}
