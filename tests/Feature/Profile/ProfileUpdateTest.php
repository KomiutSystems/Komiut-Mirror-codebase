<?php

declare(strict_types=1);

namespace Tests\Feature\Profile;

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Queues\QueueTestCase;

/**
 * Self-service profile update — the remote save the mobile edit screen needs,
 * and where a Google passenger (who signed up with no phone) adds one.
 */
final class ProfileUpdateTest extends QueueTestCase
{
    private const UPDATE = '/api/auth/profile/update';

    #[Test]
    public function a_passenger_can_add_the_phone_they_signed_up_without(): void
    {
        $user = $this->makeUser();
        $user->forceFill(['phone' => null])->save();     // e.g. arrived via Google
        Sanctum::actingAs($user);

        $this->postJson(self::UPDATE, ['phone' => '0712345678'])
            ->assertOk()
            ->assertJsonPath('user.phone', '0712345678');

        $this->assertSame('0712345678', $user->fresh()->phone);
    }

    #[Test]
    public function only_the_sent_fields_change(): void
    {
        $user = $this->makeUser();
        $originalPhone = $user->phone;
        Sanctum::actingAs($user);

        $this->postJson(self::UPDATE, ['firstname' => 'Renamed'])->assertOk();

        $fresh = $user->fresh();
        $this->assertSame('Renamed', $fresh->firstname);
        $this->assertSame($originalPhone, $fresh->phone, 'an unsent field must be left untouched');
    }

    #[Test]
    public function a_phone_already_in_use_is_rejected(): void
    {
        $taken = $this->makeUser();
        $taken->forceFill(['phone' => '0722000111'])->save();

        $me = $this->makeUser();
        Sanctum::actingAs($me);

        $this->postJson(self::UPDATE, ['phone' => '0722000111'])
            ->assertStatus(422)
            ->assertJsonPath('errors.phone.0', 'That phone number is already in use.');
    }

    #[Test]
    public function keeping_my_own_phone_is_not_a_conflict(): void
    {
        $user = $this->makeUser();
        $user->forceFill(['phone' => '0733000222'])->save();
        Sanctum::actingAs($user);

        // Re-sending the caller's own number must not trip the unique rule.
        $this->postJson(self::UPDATE, ['phone' => '0733000222', 'firstname' => 'Same'])
            ->assertOk();
    }

    #[Test]
    public function a_malformed_phone_is_rejected(): void
    {
        Sanctum::actingAs($this->makeUser());

        $this->postJson(self::UPDATE, ['phone' => '123'])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['phone']]);
    }

    #[Test]
    public function it_requires_authentication(): void
    {
        $this->postJson(self::UPDATE, ['firstname' => 'X'])->assertStatus(401);
    }
}
