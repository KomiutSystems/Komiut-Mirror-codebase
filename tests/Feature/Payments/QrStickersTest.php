<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use App\Services\Payments\QrTokenService;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Queues\QueueTestCase;

/**
 * Every sticker on a bus scans, not only one this server signed with today's
 * key: older stickers name the bus by till, plate or link (QrStickerResolver),
 * and a signed one survives the key it was signed with being replaced.
 */
final class QrStickersTest extends QueueTestCase
{
    private const RESOLVE = '/api/v1/auth/qrcode/resolve';

    private function bus(): array
    {
        $world = $this->makeWorld();
        $world['vehicle']->forceFill(['till_number' => '5202020', 'plate' => 'KDA 123A'])->save();
        Sanctum::actingAs($this->makeUser([], $world['sacco']));

        return $world;
    }

    public static function oldStickers(): array
    {
        return [
            'the till number' => ['5202020'],
            'the till, with stray spaces' => ['  5202020 '],
            'the plate, any case or spacing' => ['kda-123a'],
            'a link carrying the till' => ['https://komiut.co.ke/pay?till=5202020'],
            'a link ending in the till' => ['https://komiut.co.ke/qr/5202020'],
        ];
    }

    #[Test]
    #[DataProvider('oldStickers')]
    public function an_old_sticker_finds_its_bus(string $sticker): void
    {
        $world = $this->bus();

        $this->postJson(self::RESOLVE, ['token' => $sticker])->assertOk()
            ->assertJsonPath('vehicle.id', $world['vehicle']->id)
            ->assertJsonPath('vehicle.till_number', '5202020');
    }

    #[Test]
    public function a_signed_sticker_in_a_link_finds_its_bus(): void
    {
        $world = $this->bus();
        $token = app(QrTokenService::class)->generate(['vehicle_id' => $world['vehicle']->id]);

        $this->postJson(self::RESOLVE, ['token' => 'https://komiut.com/qr/'.$token])->assertOk()
            ->assertJsonPath('vehicle.id', $world['vehicle']->id);
    }

    #[Test]
    public function nothing_and_two_buses_are_both_refused_rather_than_guessed(): void
    {
        $world = $this->bus();
        $this->postJson(self::RESOLVE, ['token' => '9999999'])->assertStatus(422);
        $this->postJson(self::RESOLVE, ['token' => 'hello world'])->assertStatus(422);

        $twin = $this->makeVehicle($world['sacco'], $world['owner'], $world['seat']);
        $twin->forceFill(['till_number' => '5202020', 'plate' => 'KDB 999B'])->save();
        $this->postJson(self::RESOLVE, ['token' => '5202020'])->assertStatus(422);
    }

    #[Test]
    public function a_printed_sticker_survives_the_server_key_being_replaced(): void
    {
        $world = $this->bus();
        $old = 'base64:'.base64_encode(str_repeat('a', 32));
        config(['app.key' => $old, 'services.qr.signing_key' => null]);
        $printed = app(QrTokenService::class)->generate(['vehicle_id' => $world['vehicle']->id]);

        // A dedicated sticker key is set: what is on the buses still scans.
        config(['services.qr.signing_key' => 'base64:'.base64_encode(str_repeat('q', 32))]);
        $this->postJson(self::RESOLVE, ['token' => $printed])->assertOk();

        // APP_KEY itself is rotated, the old one kept as a previous key.
        config(['app.key' => 'base64:'.base64_encode(str_repeat('n', 32)), 'services.qr.previous_keys' => $old]);
        $this->postJson(self::RESOLVE, ['token' => $printed])->assertOk();

        // New stickers are signed with the sticker key, and still verify.
        $fresh = app(QrTokenService::class)->generate(['vehicle_id' => $world['vehicle']->id]);
        $this->postJson(self::RESOLVE, ['token' => $fresh])->assertOk();
    }

    #[Test]
    public function paying_from_an_old_sticker_reaches_the_bus(): void
    {
        $this->bus();

        // No M-Pesa connection in this world: the push stops at "not set up",
        // which is past the sticker -- the sticker itself was accepted.
        $this->postJson('/api/v1/auth/qrcode/stk/push', ['qr_token' => '5202020', 'amount' => 50, 'phone' => '0712345678'])
            ->assertJsonMissing(['error' => 'Invalid or tampered QR code']);
    }
}
