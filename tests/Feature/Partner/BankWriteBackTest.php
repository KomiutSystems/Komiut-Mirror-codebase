<?php

declare(strict_types=1);

namespace Tests\Feature\Partner;

use App\Models\BankTillRequest;
use App\Models\DriverBankLead;
use App\Models\Sacco;
use App\Models\Vehicle;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Queues\QueueTestCase;

/**
 * The bank writing back, and the human step between it and a vehicle's till.
 *
 * The portal was read-only until now: NCBA pulled a lead list and everything
 * after that happened in their systems, invisible to us. Adding a write path to
 * a shared-key portal changes the risk — a leaked key used to leak a list, and
 * could now inject money-routing values.
 *
 * So the rule these tests exist to hold is a single one: THE PARTNER NEVER
 * WRITES A VEHICLE'S TILL. KDY 599G is the argument — its merchant_short_code
 * was wrong for a month, its collections were invisible the whole time, and the
 * record looked perfectly healthy.
 */
final class BankWriteBackTest extends QueueTestCase
{
    private const KEY = 'test-ncba-key';

    protected function setUp(): void
    {
        parent::setUp();

        // The partner key is the identity: it selects the partner and fixes the
        // brand whose records are reachable.
        config(['bank_portal.partners' => [
            'ncba' => ['password' => self::KEY, 'brand' => 'testing', 'label' => 'NCBA Bank'],
        ]]);
    }

    /** @return array<string,string> */
    private function key(): array
    {
        return ['X-Partner-Key' => self::KEY];
    }

    private function lead(?string $account = null): DriverBankLead
    {
        $sacco = $this->makeSacco();
        $driver = $this->makeUser([], $sacco);

        return DriverBankLead::create([
            'user_id' => $driver->id,
            'brand' => 'testing',
            'bank' => 'ncba',
            'preferred_branch' => 'Thika Road',
            'account_number' => $account,
            'opted_in_at' => now(),
        ]);
    }

    private function tillRequest(Sacco $sacco, array $issued = []): BankTillRequest
    {
        return BankTillRequest::create([
            'sacco_id' => $sacco->id,
            'brand' => 'testing',
            'bank' => 'ncba',
            'subject' => 'NICCO MOVERS LIMITED',
            'endpoint_url' => 'https://komiut.com/api/rest/mpesa/confirmation_new',
            'issued_tills' => $issued,
        ]);
    }

    #[Test]
    public function the_bank_can_confirm_an_account_was_opened(): void
    {
        $lead = $this->lead();

        $this->withHeaders($this->key())
            ->postJson("/api/v1/partner/bank/leads/{$lead->id}/account", ['account_number' => '1234567890'])
            ->assertOk()
            ->assertJsonPath('lead.status', 'opened');

        $fresh = DriverBankLead::withoutGlobalScopes()->find($lead->id);
        $this->assertSame('1234567890', $fresh->account_number);
        $this->assertNotNull($fresh->account_opened_at);
    }

    #[Test]
    public function resending_the_same_confirmation_changes_nothing(): void
    {
        // Banks retry. A second identical call must be accepted rather than
        // producing an error the bank will keep retrying against.
        $lead = $this->lead();

        $this->withHeaders($this->key())
            ->postJson("/api/v1/partner/bank/leads/{$lead->id}/account", ['account_number' => '1234567890'])
            ->assertOk();
        $first = DriverBankLead::withoutGlobalScopes()->find($lead->id)->account_opened_at;

        $this->withHeaders($this->key())
            ->postJson("/api/v1/partner/bank/leads/{$lead->id}/account", ['account_number' => '1234567890'])
            ->assertOk();

        $this->assertEquals($first, DriverBankLead::withoutGlobalScopes()->find($lead->id)->account_opened_at);
    }

    #[Test]
    public function a_different_account_number_is_refused_rather_than_overwritten(): void
    {
        // Not a retry: either a mistake or somebody else's account. Silently
        // replacing it would move a driver's payouts with no trace.
        $lead = $this->lead('1111111111');

        $this->withHeaders($this->key())
            ->postJson("/api/v1/partner/bank/leads/{$lead->id}/account", ['account_number' => '2222222222'])
            ->assertStatus(409);

        $this->assertSame('1111111111', DriverBankLead::withoutGlobalScopes()->find($lead->id)->account_number);
    }

    #[Test]
    public function the_write_endpoints_need_the_partner_key(): void
    {
        $lead = $this->lead();

        $this->postJson("/api/v1/partner/bank/leads/{$lead->id}/account", ['account_number' => '1234567890'])
            ->assertStatus(401);

        $this->assertNull(DriverBankLead::withoutGlobalScopes()->find($lead->id)->account_opened_at);
    }

    #[Test]
    public function a_partner_cannot_reach_another_brands_lead(): void
    {
        // The brand comes from the key, never the body, so guessing an id gets
        // nowhere.
        $lead = $this->lead();
        DriverBankLead::withoutGlobalScopes()->where('id', $lead->id)->update(['brand' => '2safiri']);

        $this->withHeaders($this->key())
            ->postJson("/api/v1/partner/bank/leads/{$lead->id}/account", ['account_number' => '1234567890'])
            ->assertStatus(404);
    }

    #[Test]
    public function credentials_come_back_encrypted_and_never_read_back_out(): void
    {
        $request = $this->tillRequest($this->makeSacco());

        $this->withHeaders($this->key())
            ->postJson("/api/v1/partner/bank/till-requests/{$request->id}/credentials", [
                'username' => 'komiut_ncba',
                'password' => 'sup3r-s3cret',
                'secret_key' => 'sk_live_abc123',
            ])
            ->assertOk()
            // The response must not echo what was just sent.
            ->assertJsonMissing(['password' => 'sup3r-s3cret'])
            ->assertJsonPath('till_request.status', 'credentials_received');

        // Encrypted at rest: the raw column must not contain the plaintext.
        $raw = DB::table('bank_till_requests')->where('id', $request->id)->first();
        $this->assertNotSame('sup3r-s3cret', $raw->password);
        $this->assertStringNotContainsString('sup3r-s3cret', (string) $raw->password);

        // ...and still decrypts through the model, exactly once.
        $this->assertSame('sup3r-s3cret', BankTillRequest::withoutGlobalScopes()->find($request->id)->password);
    }

    #[Test]
    public function the_bank_cannot_put_a_till_on_a_vehicle(): void
    {
        // THE rule. The bank sends tills; they are staged, and the vehicle is
        // untouched until a human applies them.
        $sacco = $this->makeSacco();
        $vehicle = $this->makeVehicle($sacco, $this->makeUser([], $sacco), $this->makeSeat());
        $vehicle->forceFill(['merchant_short_code' => '4321075'])->save();
        $request = $this->tillRequest($sacco);

        $this->withHeaders($this->key())
            ->postJson("/api/v1/partner/bank/till-requests/{$request->id}/credentials", [
                'username' => 'u', 'password' => 'p', 'secret_key' => 's',
                'issued_tills' => [['plate' => $vehicle->plate, 'till' => '9999999']],
            ])
            ->assertOk();

        $this->assertSame('4321075', Vehicle::withoutGlobalScopes()->find($vehicle->id)->merchant_short_code,
            'A partner key must never change where a vehicle takes money.');
        $this->assertSame([['plate' => $vehicle->plate, 'till' => '9999999']],
            BankTillRequest::withoutGlobalScopes()->find($request->id)->issued_tills);
    }

    #[Test]
    public function a_human_applying_the_request_moves_the_till(): void
    {
        $sacco = $this->makeSacco();
        $vehicle = $this->makeVehicle($sacco, $this->makeUser([], $sacco), $this->makeSeat());
        $vehicle->forceFill(['merchant_short_code' => '4321075'])->save();
        $request = $this->tillRequest($sacco, [['plate' => $vehicle->plate, 'till' => '9999999']]);

        Sanctum::actingAs($this->makeUser(['Manage Bank Till Requests'], $sacco));

        $this->postJson("/api/v1/auth/bank/till-requests/{$request->id}/apply")
            ->assertOk()
            ->assertJsonPath('applied.0.to', '9999999');

        $fresh = Vehicle::withoutGlobalScopes()->find($vehicle->id);
        $this->assertSame('9999999', $fresh->merchant_short_code);
        $this->assertSame('9999999', $fresh->till_number);
    }

    #[Test]
    public function applying_refuses_without_the_permission(): void
    {
        $sacco = $this->makeSacco();
        $vehicle = $this->makeVehicle($sacco, $this->makeUser([], $sacco), $this->makeSeat());
        $vehicle->forceFill(['merchant_short_code' => '4321075'])->save();
        $request = $this->tillRequest($sacco, [['plate' => $vehicle->plate, 'till' => '9999999']]);

        Sanctum::actingAs($this->makeUser([], $sacco));

        $this->postJson("/api/v1/auth/bank/till-requests/{$request->id}/apply")->assertStatus(403);

        $this->assertSame('4321075', Vehicle::withoutGlobalScopes()->find($vehicle->id)->merchant_short_code);
    }

    #[Test]
    public function a_till_for_a_vehicle_outside_the_sacco_is_skipped_not_applied(): void
    {
        // Whatever plate the bank sends back, a till only lands on a vehicle of
        // the SACCO the letter was raised for.
        $mine = $this->makeSacco();
        $theirs = $this->makeSacco();
        $victim = $this->makeVehicle($theirs, $this->makeUser([], $theirs), $this->makeSeat());
        $victim->forceFill(['merchant_short_code' => '1111111'])->save();

        $request = $this->tillRequest($mine, [['plate' => $victim->plate, 'till' => '9999999']]);

        Sanctum::actingAs($this->makeUser(['Manage Bank Till Requests'], $mine));

        $this->postJson("/api/v1/auth/bank/till-requests/{$request->id}/apply")
            ->assertOk()
            ->assertJsonCount(0, 'applied')
            ->assertJsonCount(1, 'skipped');

        $this->assertSame('1111111', Vehicle::withoutGlobalScopes()->find($victim->id)->merchant_short_code);
    }

    #[Test]
    public function applying_before_the_bank_replies_is_refused(): void
    {
        $sacco = $this->makeSacco();
        $request = $this->tillRequest($sacco);

        Sanctum::actingAs($this->makeUser(['Manage Bank Till Requests'], $sacco));

        $this->postJson("/api/v1/auth/bank/till-requests/{$request->id}/apply")->assertStatus(422);
    }

    #[Test]
    public function a_letter_cannot_be_edited_once_the_bank_has_replied(): void
    {
        // Editing the till list under credentials issued against the old list
        // would leave the letter describing a request nobody made.
        $sacco = $this->makeSacco();
        $request = $this->tillRequest($sacco);
        $request->forceFill(['credentials_received_at' => now()])->save();

        Sanctum::actingAs($this->makeUser(['Manage Bank Till Requests'], $sacco));

        $this->postJson("/api/v1/auth/bank/till-requests/{$request->id}", ['subject' => 'Changed'])
            ->assertStatus(409);
    }

    #[Test]
    public function drafting_a_letter_derives_the_bank_from_the_brand(): void
    {
        // Never read from the body: sending a SACCO's tills to the wrong bank is
        // not recoverable by an apology.
        $sacco = $this->makeSacco();
        Sanctum::actingAs($this->makeUser(['Manage Bank Till Requests'], $sacco));

        $this->postJson('/api/v1/auth/bank/till-requests', [
            'sacco_id' => $sacco->id,
            'subject' => 'NICCO MOVERS LIMITED',
            'endpoint_url' => 'https://komiut.com/api/rest/mpesa/confirmation_new',
            'till_numbers' => ['4321069', '4321071'],
            'bank' => 'coop',   // ignored
        ])
            ->assertStatus(201)
            ->assertJsonPath('till_request.bank', 'ncba')
            ->assertJsonPath('till_request.paybill', '880100')
            ->assertJsonPath('till_request.has_credentials', false);
    }
}
