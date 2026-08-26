<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Support\Email;
use Database\Factories\UserFactory;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Queues\QueueTestCase;

/**
 * Signing in when the address is typed in a different case than it was stored.
 *
 * THE OUTAGE THIS PINS. Auth::attempt compares with `=`. MySQL's default
 * collation is case-INSENSITIVE, so for years `Henry@gmail.com` matched a stored
 * `henry@gmail.com` and nobody knew the two were different. PostgreSQL's `=` on
 * text is case-SENSITIVE, so the day the platform moved to Frankfurt those
 * sign-ins started failing — as WRONG PASSWORD, which sent people off to reset a
 * password that had never been wrong.
 *
 * Measured on production: 6,805 accounts have an email, 224 of them are stored
 * with an uppercase letter — including a superadmin and a SACCO admin — and
 * every account stored in lowercase is unreachable to anyone whose keyboard
 * capitalises the first letter, which phone keyboards do by themselves. Zero
 * addresses collide when lowercased, so matching case-insensitively is
 * unambiguous.
 */
final class EmailCaseTest extends QueueTestCase
{
    private const LOGIN = '/api/auth/login';

    private function accountWithEmail(string $stored): User
    {
        $user = User::factory()->create();

        // forceFill, so the stored spelling is exactly what this test says it is
        // and no normalisation on the way in can quietly fix it.
        $user->forceFill(['email' => $stored])->save();

        return $user->fresh();
    }

    #[Test]
    public function a_capitalised_address_signs_in_against_a_lowercase_row(): void
    {
        // Henry Muiruri's exact case: stored lowercase, typed with a capital.
        $this->accountWithEmail('henrymuirurih@gmail.com');

        $this->postJson(self::LOGIN, [
            'email' => 'Henrymuirurih@gmail.com',
            'password' => UserFactory::PASSWORD,
        ])->assertOk();
    }

    #[Test]
    public function a_lowercase_address_signs_in_against_a_capitalised_row(): void
    {
        // The other 224: stored with a capital, typed as people actually type.
        $this->accountWithEmail('James.Kanyangi@ncbagroup.com');

        $this->postJson(self::LOGIN, [
            'email' => 'james.kanyangi@ncbagroup.com',
            'password' => UserFactory::PASSWORD,
        ])->assertOk();
    }

    #[Test]
    public function surrounding_whitespace_does_not_stop_a_sign_in(): void
    {
        // Copy-paste from an email client brings a trailing space with it.
        $this->accountWithEmail('driver@example.test');

        $this->postJson(self::LOGIN, [
            'email' => '  Driver@Example.Test  ',
            'password' => UserFactory::PASSWORD,
        ])->assertOk();
    }

    #[Test]
    public function a_wrong_password_is_still_a_wrong_password(): void
    {
        // The fix must not have turned the case check into an auth bypass.
        $this->accountWithEmail('henrymuirurih@gmail.com');

        $this->postJson(self::LOGIN, [
            'email' => 'Henrymuirurih@gmail.com',
            'password' => 'not-the-password',
        ])->assertStatus(401);
    }

    #[Test]
    public function an_address_nobody_holds_is_still_refused(): void
    {
        $this->accountWithEmail('someone@example.test');

        $this->postJson(self::LOGIN, [
            'email' => 'NOBODY@example.test',
            'password' => UserFactory::PASSWORD,
        ])->assertStatus(401);
    }

    #[Test]
    public function the_scope_matches_nothing_for_a_blank_address(): void
    {
        // Thousands of rows have no email. A scope that matched them all on a
        // blank input would be a way to sign in as an arbitrary account.
        User::factory()->create()->forceFill(['email' => null])->save();

        $this->assertSame(0, User::byEmail(null)->count());
        $this->assertSame(0, User::byEmail('')->count());
        $this->assertSame(0, User::byEmail('   ')->count());
    }

    #[Test]
    public function registration_stores_the_address_in_one_case(): void
    {
        // So `unique` keeps meaning unique. Without this, PostgreSQL treats
        // Foo@x.com and foo@x.com as two accounts and the same mailbox.
        $this->postJson('/api/auth/register', [
            'firstname' => 'Grace',
            'lastname' => 'Wanjiku',
            'email' => 'Grace.Wanjiku@Example.TEST',
            'phone' => '0722334455',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
        ])->assertOk();

        $this->assertDatabaseHas('users', ['email' => 'grace.wanjiku@example.test']);
        $this->assertDatabaseMissing('users', ['email' => 'Grace.Wanjiku@Example.TEST']);
    }

    #[Test]
    public function the_same_address_in_a_different_case_cannot_register_twice(): void
    {
        $payload = [
            'firstname' => 'Grace',
            'lastname' => 'Wanjiku',
            'email' => 'grace@example.test',
            'phone' => '0722334455',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
        ];

        $this->postJson('/api/auth/register', $payload)->assertOk();

        $this->postJson('/api/auth/register', array_merge($payload, [
            'email' => 'GRACE@example.test',
            'phone' => '0722334456',
        ]))->assertStatus(400);

        $this->assertSame(1, User::byEmail('grace@example.test')->count());
    }

    #[Test]
    public function a_reset_link_reaches_an_account_stored_in_another_case(): void
    {
        // forgot() always answers "if that email is registered…", so a broken
        // lookup here is invisible: the person is told to check an inbox that
        // will never receive anything.
        Notification::fake();

        $user = $this->accountWithEmail('James.Kanyangi@ncbagroup.com');

        $this->postJson('/api/auth/forgot-password', [
            'email' => 'james.kanyangi@ncbagroup.com',
        ])->assertOk();

        Notification::assertSentTo($user, \App\Notifications\ResetPasswordLink::class);
    }

    #[Test]
    public function a_reset_link_is_not_sent_to_an_address_nobody_holds(): void
    {
        Notification::fake();

        $this->accountWithEmail('real@example.test');

        $this->postJson('/api/auth/forgot-password', [
            'email' => 'ghost@example.test',
        ])->assertOk();

        Notification::assertNothingSent();
    }

    #[Test]
    public function normalise_collapses_case_and_whitespace_but_not_emptiness(): void
    {
        $this->assertSame('a@b.test', Email::normalise('  A@B.TEST '));
        $this->assertSame('a@b.test', Email::normalise('a@b.test'));
        $this->assertNull(Email::normalise(null));
        $this->assertNull(Email::normalise('   '));
    }

    #[Test]
    public function password_reset_tokens_survive_a_case_difference(): void
    {
        // The token is issued against the stored spelling, so reset() has to
        // look it up the same way forgot() did or the link is dead on arrival.
        $user = $this->accountWithEmail('Mixed.Case@example.test');

        $token = Password::createToken($user);

        $this->postJson('/api/auth/reset-password', [
            'email' => 'mixed.case@example.test',
            'token' => $token,
            'password' => 'brand-new-secret',
            'password_confirmation' => 'brand-new-secret',
        ])->assertOk();

        $this->postJson(self::LOGIN, [
            'email' => 'MIXED.CASE@EXAMPLE.TEST',
            'password' => 'brand-new-secret',
        ])->assertOk();
    }
}
