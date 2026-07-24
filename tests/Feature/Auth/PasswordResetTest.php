<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Notifications\ResetPasswordLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Email-based password reset for dashboard accounts (SACCO admins & staff):
 * request a link → emailed token → set a new password.
 */
final class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    private const FORGOT = '/api/auth/forgot-password';
    private const RESET = '/api/auth/reset-password';

    #[Test]
    public function forgot_password_emails_a_reset_link(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'admin@umoja.co.ke']);

        $this->postJson(self::FORGOT, ['email' => 'admin@umoja.co.ke'])->assertOk();

        Notification::assertSentTo($user, ResetPasswordLink::class);
    }

    #[Test]
    public function forgot_password_does_not_reveal_unknown_emails(): void
    {
        Notification::fake();

        $this->postJson(self::FORGOT, ['email' => 'nobody@nowhere.test'])
            ->assertOk()
            ->assertJsonPath('message', 'If that email is registered, a reset link has been sent.');

        Notification::assertNothingSent();
    }

    #[Test]
    public function a_valid_token_resets_the_password(): void
    {
        $user = User::factory()->create(['email' => 'admin@umoja.co.ke']);
        $token = Password::createToken($user);

        $this->postJson(self::RESET, [
            'email' => 'admin@umoja.co.ke',
            'token' => $token,
            'password' => 'brandnew123',
            'password_confirmation' => 'brandnew123',
        ])->assertOk();

        $this->assertTrue(Hash::check('brandnew123', $user->fresh()->password));
    }

    #[Test]
    public function an_invalid_token_is_rejected(): void
    {
        User::factory()->create(['email' => 'admin@umoja.co.ke']);

        $this->postJson(self::RESET, [
            'email' => 'admin@umoja.co.ke',
            'token' => 'bogus-token',
            'password' => 'brandnew123',
            'password_confirmation' => 'brandnew123',
        ])->assertStatus(400);
    }
}
