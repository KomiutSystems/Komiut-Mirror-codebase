<?php

declare(strict_types=1);

namespace Tests\Feature\Queues;

use App\Services\Payments\QrTokenService;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;

/**
 * Signed fare-QR payment (mirrors the C# FareQrToken design):
 * generate a tamper-proof per-vehicle token, resolve a scan to the vehicle+till,
 * and reject any forged/tampered code.
 */
final class QrTokenPaymentTest extends QueueTestCase
{
    #[Test]
    public function token_round_trips_and_rejects_tampering(): void
    {
        $svc = app(QrTokenService::class);
        $token = $svc->generate(['vehicle_id' => 7, 'plate' => 'KDA123X']);

        $this->assertSame(7, $svc->validate($token)['vehicle_id']);

        $tampered = ($token[0] === 'a' ? 'b' : 'a') . substr($token, 1);
        $this->assertNull($svc->validate($tampered), 'a tampered payload must fail the signature');
        $this->assertNull($svc->validate('not-a-token'));
    }

    #[Test]
    public function a_sacco_can_generate_a_vehicle_qr_token(): void
    {
        $world = $this->makeWorld();
        Sanctum::actingAs($this->makeUser([], $world['sacco']));

        $token = $this->getJson('/api/auth/qrcode/vehicle/' . $world['vehicle']->id . '/token')
            ->assertOk()->json('token');

        $this->assertNotEmpty($token);
        $this->assertSame($world['vehicle']->id, app(QrTokenService::class)->validate($token)['vehicle_id']);
    }

    #[Test]
    public function scanning_resolves_the_vehicle_and_till(): void
    {
        $world = $this->makeWorld();
        $world['vehicle']->till_number = '5202020';
        $world['vehicle']->save();
        Sanctum::actingAs($this->makeUser([], $world['sacco']));

        $token = app(QrTokenService::class)->generate(['vehicle_id' => $world['vehicle']->id]);

        $this->postJson('/api/auth/qrcode/resolve', ['token' => $token])
            ->assertOk()
            ->assertJsonPath('vehicle.id', $world['vehicle']->id)
            ->assertJsonPath('vehicle.till_number', '5202020');
    }

    #[Test]
    public function a_tampered_token_is_rejected_on_resolve(): void
    {
        Sanctum::actingAs($this->makeUser([], $this->makeSacco()));

        $this->postJson('/api/auth/qrcode/resolve', ['token' => 'forged.signature'])
            ->assertStatus(422);
    }
}
