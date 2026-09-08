<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use App\Models\MpesaPaymentSetting;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Queues\QueueTestCase;

/**
 * Pointing a bus's till at this system.
 *
 * The one capability the legacy payments tier had that this one did not, and
 * therefore the thing keeping payments.komiut.com alive: Safaricom delivers a
 * C2B payment to whatever URL was registered against the shortcode, so until a
 * till is re-registered its money goes to Mumbai regardless of what runs here.
 * Frankfurt currently sees that money only because the legacy tier relays it,
 * best-effort with a one-second connect timeout and no retry.
 *
 * It is also the most dangerous endpoint on the platform: it redirects real
 * money, takes effect immediately, and Safaricom offers no dry run. These tests
 * are mostly about the guards, not the happy path.
 */
final class TillRegistrationTest extends QueueTestCase
{
    private function url(Vehicle $vehicle): string
    {
        return '/api/v1/auth/mpesa/tills/'.$vehicle->id.'/register';
    }

    private function admin(array $world, array $permissions = ['Edit Payment Settings']): User
    {
        return $this->makeUser($permissions, $world['sacco']);
    }

    private function settingsFor(array $world): MpesaPaymentSetting
    {
        return MpesaPaymentSetting::create([
            'sacco_id' => $world['sacco']->id,
            'consumer_key' => 'ck-live',
            'consumer_secret' => 'cs-live',
            'business_short_code' => '4123456',
            'pass_key' => 'pk',
            'payment_mode' => 'CustomerBuyGoodsOnline',
            'is_live' => true,
            'status' => true,
        ]);
    }

    private function tillOn(array $world, string $shortCode = '7100466'): Vehicle
    {
        $vehicle = $world['vehicle'];
        $vehicle->forceFill(['merchant_short_code' => $shortCode])->save();

        return $vehicle->fresh();
    }

    /** Safaricom accepting the registration. */
    private function safaricomAccepts(): void
    {
        Http::fake([
            '*/oauth/v1/generate*' => Http::response(['access_token' => 'tok', 'expires_in' => '3599']),
            '*/mpesa/c2b/v2/registerurl' => Http::response([
                'ResponseCode' => '0', 'ResponseDescription' => 'Success',
            ]),
        ]);
    }

    #[Test]
    public function registering_a_till_points_it_at_this_system(): void
    {
        $world = $this->makeWorld();
        $setting = $this->settingsFor($world);
        $vehicle = $this->tillOn($world);
        $this->safaricomAccepts();

        Sanctum::actingAs($this->admin($world));
        $this->postJson($this->url($vehicle))->assertOk();

        $fresh = $vehicle->fresh();
        $this->assertNotNull($fresh->till_registered_at);
        $this->assertSame(
            rtrim((string) config('app.url'), '/').'/api/confirmation/'.$setting->id,
            $fresh->till_registered_url,
            'the URL Safaricom accepted is recorded, so "has this bus moved" is a comparison, not a guess',
        );
    }

    #[Test]
    public function it_registers_the_BUS_shortcode_not_the_credentials_shortcode(): void
    {
        // THE MISTAKE THIS EXISTS TO PREVENT. One Daraja app covers a whole
        // fleet; each bus has its own merchant_short_code. Sending the
        // credential's shortcode would move one bus's money onto another's till.
        $world = $this->makeWorld();
        $this->settingsFor($world);                 // business_short_code 4123456
        $vehicle = $this->tillOn($world, '7100466'); // the bus's own till
        $this->safaricomAccepts();

        Sanctum::actingAs($this->admin($world));
        $this->postJson($this->url($vehicle))->assertOk();

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'registerurl')
                && $request['ShortCode'] === '7100466';
        });
    }

    #[Test]
    public function the_confirmation_url_is_keyed_on_the_settings_id_not_the_vehicle(): void
    {
        // Many tills share one Daraja app and therefore one callback URL. The
        // receiving end attributes by the payload's BusinessShortCode. A
        // per-vehicle URL would look tidier and quietly break that contract.
        $world = $this->makeWorld();
        $setting = $this->settingsFor($world);
        $vehicle = $this->tillOn($world);
        $this->safaricomAccepts();

        Sanctum::actingAs($this->admin($world));
        $this->postJson($this->url($vehicle))->assertOk();

        Http::assertSent(function ($request) use ($setting, $vehicle) {
            if (! str_contains($request->url(), 'registerurl')) {
                return false;
            }

            return str_ends_with($request['ConfirmationURL'], '/api/confirmation/'.$setting->id)
                && ! str_ends_with($request['ConfirmationURL'], '/api/confirmation/'.$vehicle->id);
        });
    }

    #[Test]
    public function a_refusal_from_safaricom_is_not_recorded_as_a_move(): void
    {
        $world = $this->makeWorld();
        $this->settingsFor($world);
        $vehicle = $this->tillOn($world);

        Http::fake([
            '*/oauth/v1/generate*' => Http::response(['access_token' => 'tok']),
            '*/mpesa/c2b/v2/registerurl' => Http::response([
                'errorCode' => '400.002.02', 'errorMessage' => 'Bad Request - Invalid ShortCode',
            ], 400),
        ]);

        Sanctum::actingAs($this->admin($world));
        $this->postJson($this->url($vehicle))->assertStatus(422);

        $this->assertNull($vehicle->fresh()->till_registered_at, 'a refused till has not moved');
    }

    #[Test]
    public function an_unreachable_safaricom_is_reported_as_unknown_not_as_success(): void
    {
        // Recording a registration we cannot prove is worse than recording none:
        // the fleet view would show the bus as moved while its money still goes
        // to Mumbai, and nobody would look at it again.
        $world = $this->makeWorld();
        $this->settingsFor($world);
        $vehicle = $this->tillOn($world);

        Http::fake(['*/oauth/v1/generate*' => Http::response([], 500)]);

        Sanctum::actingAs($this->admin($world));
        $this->postJson($this->url($vehicle))->assertStatus(502);

        $this->assertNull($vehicle->fresh()->till_registered_at);
    }

    #[Test]
    public function a_vehicle_with_no_short_code_is_refused_before_safaricom_is_called(): void
    {
        $world = $this->makeWorld();
        $this->settingsFor($world);
        $vehicle = $world['vehicle'];
        $vehicle->forceFill(['merchant_short_code' => null])->save();
        Http::fake();

        Sanctum::actingAs($this->admin($world));
        $this->postJson($this->url($vehicle))->assertStatus(422);

        Http::assertNothingSent();
    }

    #[Test]
    public function a_sacco_with_no_credentials_is_refused(): void
    {
        $world = $this->makeWorld();
        $vehicle = $this->tillOn($world);          // no MpesaPaymentSetting
        Http::fake();

        Sanctum::actingAs($this->admin($world));
        $this->postJson($this->url($vehicle))->assertStatus(422);

        Http::assertNothingSent();
    }

    #[Test]
    public function another_saccos_till_cannot_be_repointed(): void
    {
        // THE ONE THAT MATTERS MOST. Route-model binding resolves a vehicle from
        // the whole fleet, so without the tenant check a SACCO admin could point
        // someone else's till at their own confirmation URL.
        $mine = $this->makeWorld();
        $theirs = $this->makeWorld();
        $this->settingsFor($theirs);
        $vehicle = $this->tillOn($theirs);
        Http::fake();

        Sanctum::actingAs($this->admin($mine));
        $this->postJson($this->url($vehicle))->assertStatus(404);

        Http::assertNothingSent();
        $this->assertNull($vehicle->fresh()->till_registered_at);
    }

    #[Test]
    public function it_needs_permission_to_manage_payment_settings(): void
    {
        $world = $this->makeWorld();
        $this->settingsFor($world);
        $vehicle = $this->tillOn($world);
        Http::fake();

        Sanctum::actingAs($this->makeUser(['View Payment Settings'], $world['sacco']));
        $this->postJson($this->url($vehicle))->assertStatus(403);

        Http::assertNothingSent();
        $this->assertNull($vehicle->fresh()->till_registered_at);
    }

    #[Test]
    public function a_shortcode_with_stray_formatting_is_normalised(): void
    {
        // Hand-entered, and Safaricom rejects anything but digits.
        $world = $this->makeWorld();
        $this->settingsFor($world);
        $vehicle = $this->tillOn($world, ' 0712345 ');
        $this->safaricomAccepts();

        Sanctum::actingAs($this->admin($world));
        $this->postJson($this->url($vehicle))->assertOk();

        Http::assertSent(fn ($request) => ! str_contains($request->url(), 'registerurl')
            || $request['ShortCode'] === '712345');
    }

    // ------------------------------------------------------------ rollback

    #[Test]
    public function a_till_can_be_pointed_back_at_the_legacy_tier(): void
    {
        // THE ONLY UNDO SAFARICOM OFFERS. Same path, host swapped — which works
        // because the import preserves the legacy setting id.
        $world = $this->makeWorld();
        $setting = $this->settingsFor($world);
        $vehicle = $this->tillOn($world);
        $this->safaricomAccepts();
        config(['services.legacy_payments.url' => 'https://payments.komiut.com']);

        Sanctum::actingAs($this->admin($world));
        $this->postJson($this->url($vehicle), ['destination' => 'legacy'])
            ->assertOk()
            ->assertJsonPath('destination', 'legacy');

        $expected = 'https://payments.komiut.com/api/confirmation/'.$setting->id;
        Http::assertSent(fn ($r) => ! str_contains($r->url(), 'registerurl')
            || $r['ConfirmationURL'] === $expected);
        $this->assertSame($expected, $vehicle->fresh()->till_registered_url,
            'a rollback to Mumbai is recorded AS Mumbai, which a boolean could never express');
    }

    #[Test]
    public function destination_defaults_to_this_system(): void
    {
        $world = $this->makeWorld();
        $setting = $this->settingsFor($world);
        $vehicle = $this->tillOn($world);
        $this->safaricomAccepts();

        Sanctum::actingAs($this->admin($world));
        $this->postJson($this->url($vehicle))->assertOk()->assertJsonPath('destination', 'here');

        $this->assertStringStartsWith(rtrim((string) config('app.url'), '/'), $vehicle->fresh()->till_registered_url);
    }

    #[Test]
    public function an_arbitrary_destination_url_is_refused_outright(): void
    {
        // "Register this till to any host" is "redirect this bus's income to
        // any host". No permission on the platform should grant that, so the
        // field is an allowlist of two words, not a URL.
        $world = $this->makeWorld();
        $this->settingsFor($world);
        $vehicle = $this->tillOn($world);
        Http::fake();

        Sanctum::actingAs($this->admin($world));
        // No empty string here: ConvertEmptyStringsToNull would turn it into
        // the default, which is the correct behaviour, not a refusal.
        foreach (['https://evil.example/api/confirmation/1', 'mumbai', 'HERE'] as $bad) {
            $this->postJson($this->url($vehicle), ['destination' => $bad])->assertStatus(422);
        }

        Http::assertNothingSent();
        $this->assertNull($vehicle->fresh()->till_registered_at);
    }
}
