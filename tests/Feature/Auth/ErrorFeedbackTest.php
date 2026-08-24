<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\Sacco;
use App\Models\User;
use Database\Factories\UserFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * What a person actually reads when a sign-up is rejected.
 *
 * A SACCO admin registered with a phone that was already in use and saw NOTHING
 * on screen. The API had answered
 *
 *     {"errors":{"phone":["The phone has already been taken."]}}
 *
 * which is two failures at once. The envelope carried no top-level summary, so a
 * form that renders one banner had nothing to render; and the message was a
 * Laravel default that states the fault without naming the fix, so even shown it
 * leaves the person stuck between "sign in" and "use another number".
 *
 * These pin the contract both halves depend on: a `message` that can go straight
 * into a banner, and per-field `errors` for inline display, with every string
 * telling the reader what to DO.
 */
final class ErrorFeedbackTest extends TestCase
{
    use RefreshDatabase;

    private const REGISTER_SACCO = '/api/auth/register/sacco';

    private const REGISTER = '/api/auth/register';

    private const LOGIN = '/api/auth/login';

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
    }

    /** @return array<string, string> */
    private function saccoPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Tony Sacco',
            'email' => 'admin@tonysacco.com',
            'phone' => '0705708643',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
        ], $overrides);
    }

    #[Test]
    public function a_taken_phone_says_what_to_do_about_it(): void
    {
        // The exact reported case.
        (new UserFactory)->create(['phone' => '0705708643']);

        $response = $this->postJson(self::REGISTER_SACCO, $this->saccoPayload())
            ->assertStatus(400);

        $expected = 'This phone number is already registered. Sign in instead, or use a different number.';

        // The banner-ready summary that was missing entirely.
        $response->assertJsonPath('message', $expected);
        // And the per-field key, unchanged in shape so nothing that reads it breaks.
        $response->assertJsonPath('errors.phone.0', $expected);
    }

    #[Test]
    public function a_taken_email_says_what_to_do_about_it(): void
    {
        (new UserFactory)->create(['email' => 'admin@tonysacco.com']);

        $this->postJson(self::REGISTER_SACCO, $this->saccoPayload(['phone' => '0722000111']))
            ->assertStatus(400)
            ->assertJsonPath('message', 'This email is already registered. Sign in instead, or use a different address.');
    }

    #[Test]
    public function an_email_already_used_by_another_sacco_is_caught_too(): void
    {
        Sacco::factory()->create(['email' => 'admin@tonysacco.com']);

        $this->postJson(self::REGISTER_SACCO, $this->saccoPayload(['phone' => '0722000112']))
            ->assertStatus(400)
            ->assertJsonPath('message', 'This email is already registered. Sign in instead, or use a different address.');
    }

    #[Test]
    public function a_sacco_can_register_with_an_international_phone_format(): void
    {
        // The consistency bug behind half the confusion: this form demanded
        // exactly ten digits while the passenger form beside it accepted any
        // Kenyan format, so +254... failed here with "must be 10 digits" and
        // nothing said that dropping the +254 would fix it.
        $this->postJson(self::REGISTER_SACCO, $this->saccoPayload(['phone' => '+254705708643']))
            ->assertOk();

        // Stored in the one canonical local form, so unique and login agree.
        $this->assertDatabaseHas('users', ['phone' => '0705708643']);
    }

    #[Test]
    public function a_phone_that_is_not_kenyan_says_what_a_good_one_looks_like(): void
    {
        $this->postJson(self::REGISTER_SACCO, $this->saccoPayload(['phone' => '12345']))
            ->assertStatus(400)
            ->assertJsonPath('message', 'Enter a valid Kenyan mobile number, for example 0712345678.')
            ->assertJsonPath('errors.phone.0', 'Enter a valid Kenyan mobile number, for example 0712345678.');
    }

    #[Test]
    public function mismatched_passwords_say_so_plainly(): void
    {
        $this->postJson(self::REGISTER_SACCO, $this->saccoPayload(['password_confirmation' => 'different1']))
            ->assertStatus(400)
            ->assertJsonPath('message', "The two passwords don't match.");
    }

    #[Test]
    public function a_short_password_names_the_actual_minimum(): void
    {
        $this->postJson(self::REGISTER_SACCO, $this->saccoPayload([
            'password' => 'short', 'password_confirmation' => 'short',
        ]))
            ->assertStatus(400)
            ->assertJsonPath('message', 'Your password needs at least 8 characters.');
    }

    #[Test]
    public function an_empty_form_names_the_first_thing_to_fix_not_an_arbitrary_one(): void
    {
        // The summary follows the rules in declared order, so it points at the
        // top of the form rather than wherever the map happened to iterate.
        $this->postJson(self::REGISTER_SACCO, [])
            ->assertStatus(400)
            ->assertJsonPath('message', "Enter your SACCO's name.")
            ->assertJsonStructure(['message', 'errors' => ['name', 'email', 'phone', 'password']]);
    }

    #[Test]
    public function the_passenger_signup_explains_itself_the_same_way(): void
    {
        (new UserFactory)->create(['email' => 'rider@example.test']);

        $this->postJson(self::REGISTER, [
            'firstname' => 'A', 'lastname' => 'B',
            'email' => 'rider@example.test', 'phone' => '0733111222',
            'password' => 'secret123', 'password_confirmation' => 'secret123',
        ])
            ->assertStatus(400)
            ->assertJsonPath('message', 'This email is already registered. Sign in instead, or use a different address.');
    }

    #[Test]
    public function signing_in_with_nothing_asks_for_what_is_missing(): void
    {
        // Laravel's own wording here is "The email field is required when phone
        // is not present", which describes the RULE rather than what the person
        // left blank.
        $this->postJson(self::LOGIN, [])
            ->assertStatus(400)
            ->assertJsonPath('message', 'Enter your email address or phone number.');
    }

    #[Test]
    public function a_wrong_password_does_not_reveal_whether_the_account_exists(): void
    {
        (new UserFactory)->create(['email' => 'rider@example.test', 'password' => 'secret123']);

        $known = $this->postJson(self::LOGIN, ['email' => 'rider@example.test', 'password' => 'wrong-one'])
            ->assertStatus(401)->json('message');

        $unknown = $this->postJson(self::LOGIN, ['email' => 'nobody@example.test', 'password' => 'wrong-one'])
            ->assertStatus(401)->json('message');

        $this->assertSame($known, $unknown, 'A known and an unknown account must be indistinguishable.');
        $this->assertStringContainsString('Check your email or phone number and password', (string) $known);
    }
}
