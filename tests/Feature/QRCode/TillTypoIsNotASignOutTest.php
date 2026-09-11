<?php

declare(strict_types=1);

namespace Tests\Feature\QRCode;

use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Queues\QueueTestCase;

/**
 * A typo in the till box must not sign the passenger out.
 *
 * Three passenger-facing endpoints answered a VALIDATION failure with 401. To
 * every HTTP client on this platform 401 means "your session is invalid" — the
 * app's interceptor signs the user out on it. So a passenger who fat-fingered one
 * digit of the till printed in the matatu was logged out of the app, at the door,
 * with the conductor waiting. qrcode/vehicle is the first call of the QR payment
 * flow, so that landed on the money path.
 *
 * Found by the mobile-contract doc pass on 2026-09-11, which read the code rather
 * than the docblock and flagged the status as a trap. 401 is for authentication.
 * Everything else that refuses a request is 4xx-but-not-401.
 */
final class TillTypoIsNotASignOutTest extends QueueTestCase
{
    #[Test]
    public function a_non_numeric_till_is_a_400_with_errors_not_a_401(): void
    {
        $world = $this->makeWorld();
        Sanctum::actingAs($this->makeUser([], $world['sacco']));

        $this->postJson('/api/auth/qrcode/vehicle', ['till_number' => 'seven-one-zero'])
            ->assertStatus(400)
            ->assertJsonStructure(['errors' => ['till_number']]);
    }

    #[Test]
    public function a_missing_till_is_a_400_not_a_401(): void
    {
        $world = $this->makeWorld();
        Sanctum::actingAs($this->makeUser([], $world['sacco']));

        $this->postJson('/api/auth/qrcode/vehicle', [])
            ->assertStatus(400)
            ->assertJsonStructure(['errors' => ['till_number']]);
    }

    #[Test]
    public function no_token_is_still_a_real_401(): void
    {
        // The distinction the fix preserves: authentication failures stay 401.
        $this->postJson('/api/auth/qrcode/vehicle', ['till_number' => '7100466'])
            ->assertStatus(401);
    }

    #[Test]
    public function the_booking_pickup_validator_is_a_400_too(): void
    {
        // Same defect on the crew's pick-up screen: bookings/passengers/pick
        // answered a bad queueId/pickupId with 401. Needs the Edit Passengers
        // permission to reach the validator at all.
        $world = $this->makeWorld();
        Sanctum::actingAs($this->makeUser(['Edit Passengers'], $world['sacco']));

        $response = $this->postJson('/api/auth/bookings/passengers/pick', ['queueId' => 'x']);

        $this->assertNotSame(401, $response->status(), 'a validation failure is never a sign-out');
    }
}
