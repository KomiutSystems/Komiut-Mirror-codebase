<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Enums\UserType;
use App\Jobs\SendSMSJob;
use App\Models\Crew;
use App\Models\Gender;
use App\Models\Sacco;
use App\Models\User;
use Database\Factories\CrewFactory;
use Database\Factories\UserFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Regression tests for App\Http\Controllers\APIs\AuthController.
 *
 * These capture CURRENT behaviour of the auth endpoints; they are not a
 * statement of what the behaviour ought to be.
 */
final class AuthControllerTest extends TestCase
{
    use RefreshDatabase;

    private const string LOGIN = '/api/auth/login';

    private const string REGISTER = '/api/auth/register';

    private const string RESET = '/api/auth/reset_password';

    /**
     * The sign-in refusal, deliberately identical for a wrong password and an
     * unknown account so neither can be used to enumerate the other.
     */
    private const string SIGN_IN_REFUSED = "We couldn't sign you in. Check your email or phone number and password, then try again.";

    private const string PHONE_NOT_FOUND = "We don't have an account with that phone number. Check the number, or register instead.";

    protected function setUp(): void
    {
        parent::setUp();

        // Nothing in this suite may reach the SMS gateway.
        Bus::fake();
    }

    // ---------------------------------------------------------------- login

    #[Test]
    public function login_with_email_and_password_returns_a_token(): void
    {
        $user = User::factory()->create();

        $response = $this->postJson(self::LOGIN, [
            'email' => $user->email,
            'password' => UserFactory::PASSWORD,
        ]);

        $response->assertOk();
        $this->assertNotEmpty($response->json('access_token'));
        $this->assertSame('bearer', $response->json('token_type'));
        $this->assertSame($user->id, $response->json('user.id'));
        // The email path never resolves a crew.
        $this->assertNull($response->json('crew'));
    }

    #[Test]
    public function login_response_has_the_expected_token_payload_shape(): void
    {
        $sacco = Sacco::factory()->create();
        $user = User::factory()->create(['sacco_id' => $sacco->id]);

        $response = $this->postJson(self::LOGIN, [
            'email' => $user->email,
            'password' => UserFactory::PASSWORD,
        ]);

        $response->assertOk();
        $response->assertJsonStructure([
            'user',
            'crew',
            'permissions',
            'vehicle_users',
            'termini',
            'sacco',
            'access_token',
            'token_type',
        ]);

        $this->assertSame($sacco->id, $response->json('sacco.id'));
        $this->assertSame([], $response->json('permissions'));
        $this->assertSame([], $response->json('vehicle_users'));
        $this->assertSame([], $response->json('termini'));
        // `password` is hidden on the User model.
        $this->assertArrayNotHasKey('password', (array) $response->json('user'));
    }

    #[Test]
    public function login_with_a_valid_crew_phone_and_password_logs_in_the_linked_user(): void
    {
        $owner = User::factory()->create();
        $crew = Crew::factory()->create([
            'user_id' => $owner->id,
            'created_by' => $owner->id,
            'status' => true,
        ]);

        $response = $this->postJson(self::LOGIN, [
            'phone' => $crew->phone,
            'password' => CrewFactory::PASSWORD,
        ]);

        $response->assertOk();
        // Authenticated as the crew's linked user, not as the crew itself.
        $this->assertSame($owner->id, $response->json('user.id'));
        $this->assertSame($crew->id, $response->json('crew.id'));
        $this->assertNotEmpty($response->json('access_token'));
        // Crew hides its password column.
        $this->assertArrayNotHasKey('password', (array) $response->json('crew'));
    }

    #[Test]
    public function login_with_a_crew_phone_but_wrong_password_falls_through_to_a_401(): void
    {
        $owner = User::factory()->create();
        $crew = Crew::factory()->create([
            'user_id' => $owner->id,
            'created_by' => $owner->id,
        ]);

        $response = $this->postJson(self::LOGIN, [
            'phone' => $crew->phone,
            'password' => 'definitely-not-the-crew-password',
        ]);

        // Crew is discarded, then Auth::attempt(['phone','password']) fails
        // because no *user* owns that phone number.
        $response->assertStatus(401);
        $response->assertJsonPath('error', self::SIGN_IN_REFUSED)
            ->assertJsonPath('message', self::SIGN_IN_REFUSED);
        $this->assertGuest();
    }

    #[Test]
    public function login_ignores_a_crew_whose_status_is_false(): void
    {
        $owner = User::factory()->create();
        $crew = Crew::factory()->inactive()->create([
            'user_id' => $owner->id,
            'created_by' => $owner->id,
        ]);

        $response = $this->postJson(self::LOGIN, [
            'phone' => $crew->phone,
            'password' => CrewFactory::PASSWORD,
        ]);

        $response->assertStatus(401);
        $response->assertJsonPath('error', self::SIGN_IN_REFUSED)
            ->assertJsonPath('message', self::SIGN_IN_REFUSED);
    }

    #[Test]
    public function login_with_a_user_phone_and_password_authenticates_the_user(): void
    {
        $user = User::factory()->create();

        $response = $this->postJson(self::LOGIN, [
            'phone' => $user->phone,
            'password' => UserFactory::PASSWORD,
        ]);

        $response->assertOk();
        $this->assertSame($user->id, $response->json('user.id'));
        // No crew row matched, so `crew` stays null.
        $this->assertNull($response->json('crew'));
    }

    #[Test]
    public function an_email_sent_in_the_phone_field_still_authenticates_by_email(): void
    {
        // The dashboard's single "email or phone" field may send an email value
        // under `phone`. It must be recognised as an email, not looked up (and
        // missed) in the phone column — the bug that failed every email login.
        $user = User::factory()->create();

        $response = $this->postJson(self::LOGIN, [
            'phone' => $user->email,
            'password' => UserFactory::PASSWORD,
        ]);

        $response->assertOk();
        $this->assertSame($user->id, $response->json('user.id'));
        $this->assertNull($response->json('crew'));
    }

    #[Test]
    public function an_email_sent_in_both_fields_authenticates_by_email(): void
    {
        // A frontend that populates both `email` and `phone` from one field must
        // not have `phone` hijack the auth to the phone column.
        $user = User::factory()->create();

        $response = $this->postJson(self::LOGIN, [
            'email' => $user->email,
            'phone' => $user->email,
            'password' => UserFactory::PASSWORD,
        ]);

        $response->assertOk();
        $this->assertSame($user->id, $response->json('user.id'));
    }

    #[Test]
    public function login_with_invalid_credentials_returns_401(): void
    {
        $user = User::factory()->create();

        $response = $this->postJson(self::LOGIN, [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

        $response->assertStatus(401);
        $response->assertJsonPath('error', self::SIGN_IN_REFUSED)
            ->assertJsonPath('message', self::SIGN_IN_REFUSED);
    }

    #[Test]
    public function login_with_an_unknown_email_returns_401(): void
    {
        $response = $this->postJson(self::LOGIN, [
            'email' => 'nobody@example.com',
            'password' => 'whatever',
        ]);

        $response->assertStatus(401);
        $response->assertJsonPath('error', self::SIGN_IN_REFUSED)
            ->assertJsonPath('message', self::SIGN_IN_REFUSED);
    }

    #[Test]
    public function login_without_a_password_fails_validation_with_400(): void
    {
        $response = $this->postJson(self::LOGIN, [
            'email' => 'someone@example.com',
        ]);

        $response->assertStatus(400);
        $response->assertJsonPath('errors.password.0', 'Enter your password.')
            ->assertJsonPath('message', 'Enter your password.');
    }

    // ------------------------------------------------------------- register

    #[Test]
    public function register_creates_the_user_assigns_the_user_role_and_returns_a_token(): void
    {
        $gender = Gender::factory()->create();
        $this->assertNull(Role::where('name', 'User')->first());

        $response = $this->postJson(self::REGISTER, [
            'firstname' => 'Jane',
            'lastname' => 'Doe',
            'email' => 'jane.doe@example.com',
            'phone' => '0712345678',
            'password' => 'secret-password',
            'password_confirmation' => 'secret-password',
            'dob' => '1990-01-01',
            'gender' => $gender->name,
        ]);

        $response->assertOk();
        $response->assertJsonStructure([
            'user',
            'crew',
            'permissions',
            'vehicle_users',
            'termini',
            'sacco',
            'access_token',
            'token_type',
        ]);
        $this->assertNotEmpty($response->json('access_token'));
        $this->assertSame('bearer', $response->json('token_type'));

        $this->assertDatabaseHas('users', [
            'email' => 'jane.doe@example.com',
            'phone' => '0712345678',
            'gender_id' => $gender->id,
        ]);

        // The `User` role is created on demand and assigned.
        $role = Role::where('name', 'User')->first();
        $this->assertNotNull($role);

        $user = User::where('email', 'jane.doe@example.com')->firstOrFail();
        $this->assertTrue($user->hasRole('User'));
        $this->assertTrue(Hash::check('secret-password', $user->password));
        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    #[Test]
    public function register_accepts_an_international_phone_and_stores_it_canonically(): void
    {
        // The mobile app may send +254…; it must register and be stored as the
        // canonical local 0… form, so a later 0…/254… login still finds the row.
        $gender = Gender::factory()->create();

        $response = $this->postJson(self::REGISTER, [
            'firstname' => 'Amina',
            'lastname' => 'Yusuf',
            'email' => 'amina.yusuf@example.com',
            'phone' => '+254712345678',
            'password' => 'secret-password',
            'password_confirmation' => 'secret-password',
            'dob' => '1990-01-01',
            'gender' => $gender->name,
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('users', [
            'email' => 'amina.yusuf@example.com',
            'phone' => '0712345678',
        ]);
    }

    #[Test]
    public function register_rejects_a_non_kenyan_phone_with_400(): void
    {
        $gender = Gender::factory()->create();

        $response = $this->postJson(self::REGISTER, [
            'firstname' => 'Bad',
            'lastname' => 'Number',
            'email' => 'bad.number@example.com',
            'phone' => '+255712345678', // Tanzania
            'password' => 'secret-password',
            'password_confirmation' => 'secret-password',
            'dob' => '1990-01-01',
            'gender' => $gender->name,
        ]);

        $response->assertStatus(400);
        $this->assertNotEmpty($response->json('errors.phone'));
        $this->assertDatabaseMissing('users', ['email' => 'bad.number@example.com']);
    }

    #[Test]
    public function login_accepts_an_international_phone_for_a_locally_stored_user(): void
    {
        // Row written as 0…, app logs in with +254… — must still resolve.
        $user = User::factory()->create(['phone' => '0712345678']);

        $response = $this->postJson(self::LOGIN, [
            'phone' => '+254712345678',
            'password' => UserFactory::PASSWORD,
        ]);

        $response->assertOk();
        $this->assertSame($user->id, $response->json('user.id'));
    }

    #[Test]
    public function register_reuses_an_existing_user_role(): void
    {
        $gender = Gender::factory()->create();
        $role = Role::create(['name' => 'User', 'guard_name' => 'web']);

        $response = $this->postJson(self::REGISTER, [
            'firstname' => 'John',
            'lastname' => 'Smith',
            'email' => 'john.smith@example.com',
            'phone' => '0798765432',
            'password' => 'secret-password',
            'password_confirmation' => 'secret-password',
            'dob' => '1991-02-02',
            'gender' => $gender->name,
        ]);

        $response->assertOk();
        $this->assertSame(1, Role::where('name', 'User')->count());
        $this->assertSame(
            $role->id,
            User::where('email', 'john.smith@example.com')->firstOrFail()->roles->first()->id
        );
    }

    #[Test]
    public function register_returns_400_when_required_fields_are_missing(): void
    {
        $response = $this->postJson(self::REGISTER, []);

        $response->assertStatus(400);
        // gender is no longer collected at sign-up (users.gender_id is nullable)
        $response->assertJsonStructure([
            'errors' => ['firstname', 'lastname', 'email', 'phone', 'password'],
        ]);
        $this->assertDatabaseCount('users', 0);
    }

    #[Test]
    public function register_returns_400_for_a_duplicate_email(): void
    {
        $gender = Gender::factory()->create();
        $existing = User::factory()->create();

        $response = $this->postJson(self::REGISTER, [
            'firstname' => 'Dup',
            'lastname' => 'Licate',
            'email' => $existing->email,
            'phone' => '0700000001',
            'password' => 'secret-password',
            'password_confirmation' => 'secret-password',
            'dob' => '1990-01-01',
            'gender' => $gender->name,
        ]);

        $response->assertStatus(400);
        $this->assertNotEmpty($response->json('errors.email'));
    }

    #[Test]
    public function register_returns_400_when_the_password_confirmation_does_not_match(): void
    {
        $gender = Gender::factory()->create();

        $response = $this->postJson(self::REGISTER, [
            'firstname' => 'Mis',
            'lastname' => 'Match',
            'email' => 'mismatch@example.com',
            'phone' => '0700000002',
            'password' => 'secret-password',
            'password_confirmation' => 'something-else',
            'dob' => '1990-01-01',
            'gender' => $gender->name,
        ]);

        $response->assertStatus(400);
        $this->assertNotEmpty($response->json('errors.password'));
        $this->assertDatabaseMissing('users', ['email' => 'mismatch@example.com']);
    }

    #[Test]
    public function register_returns_400_for_an_unknown_gender(): void
    {
        $response = $this->postJson(self::REGISTER, [
            'firstname' => 'No',
            'lastname' => 'Gender',
            'email' => 'nogender@example.com',
            'phone' => '0700000003',
            'password' => 'secret-password',
            'password_confirmation' => 'secret-password',
            'dob' => '1990-01-01',
            'gender' => 'NotARealGender',
        ]);

        $response->assertStatus(400);
        $this->assertNotEmpty($response->json('errors.gender'));
    }

    // -------------------------------------------------------- reset password

    #[Test]
    public function reset_password_for_an_unknown_phone_returns_401(): void
    {
        $response = $this->postJson(self::RESET, ['phone' => '0700000009']);

        $response->assertStatus(401);
        $response->assertJsonPath('error', self::PHONE_NOT_FOUND)
            ->assertJsonPath('message', self::PHONE_NOT_FOUND);
        Bus::assertNotDispatched(SendSMSJob::class);
    }

    #[Test]
    public function reset_password_issues_a_temporary_password_without_touching_the_real_one(): void
    {
        // This used to overwrite users.password outright. auth/reset_password is
        // PUBLIC and finds people by phone number, so that let anyone lock any
        // account out of itself, as often as the throttle allowed — the SMS went
        // to the real owner, so an attacker gained nothing but the denial.
        $user = User::factory()->create(['phone' => '0712345678']);
        $originalHash = $user->getAuthPassword();

        $response = $this->postJson(self::RESET, ['phone' => $user->phone]);

        $response->assertOk();
        // The response contract is unchanged — the mobile apps already ship
        // against this exact string.
        $response->assertExactJson([
            'success' => 'New Password has been sent to 0712345678. Use it to login.',
        ]);

        Bus::assertDispatched(SendSMSJob::class);

        $user->refresh();
        $this->assertSame($originalHash, $user->password, 'the real password must survive an unrequested reset');
        $this->assertTrue(Hash::check(UserFactory::PASSWORD, $user->password));
        $this->assertNotNull($user->sms_reset_password, 'a temporary password must have been issued');
        $this->assertTrue($user->sms_reset_expires_at->isFuture());
    }

    #[Test]
    public function the_temporary_password_signs_you_in_once_and_is_then_spent(): void
    {
        $user = User::factory()->create(['phone' => '0712345678']);

        // Capture what was actually texted rather than reaching into the column,
        // which holds only a hash.
        $sent = null;
        Bus::assertNotDispatched(SendSMSJob::class);
        $this->postJson(self::RESET, ['phone' => $user->phone])->assertOk();
        Bus::assertDispatched(SendSMSJob::class, function (SendSMSJob $job) use (&$sent) {
            $sent = $job;

            return true;
        });

        $temporary = $this->temporaryPasswordFrom($sent);

        $this->postJson(self::LOGIN, ['phone' => '0712345678', 'password' => $temporary])
            ->assertOk();

        $this->assertNull($user->fresh()->sms_reset_password, 'a single-use password must be consumed');

        $this->postJson(self::LOGIN, ['phone' => '0712345678', 'password' => $temporary])
            ->assertStatus(401);
    }

    #[Test]
    public function the_original_password_still_works_after_a_reset_nobody_asked_for(): void
    {
        // The property the whole redesign exists for: an unrequested reset costs
        // its victim one confusing SMS, not their account.
        $user = User::factory()->create(['phone' => '0712345678']);

        $this->postJson(self::RESET, ['phone' => $user->phone])->assertOk();

        $this->postJson(self::LOGIN, ['phone' => '0712345678', 'password' => UserFactory::PASSWORD])
            ->assertOk();
    }

    #[Test]
    public function an_expired_temporary_password_does_not_sign_you_in(): void
    {
        $user = User::factory()->create(['phone' => '0712345678']);

        $sent = null;
        $this->postJson(self::RESET, ['phone' => $user->phone])->assertOk();
        Bus::assertDispatched(SendSMSJob::class, function (SendSMSJob $job) use (&$sent) {
            $sent = $job;

            return true;
        });

        $temporary = $this->temporaryPasswordFrom($sent);

        $user->forceFill(['sms_reset_expires_at' => now()->subMinute()])->save();

        $this->postJson(self::LOGIN, ['phone' => '0712345678', 'password' => $temporary])
            ->assertStatus(401);
    }

    #[Test]
    public function a_staff_account_cannot_be_reset_by_sms_and_the_response_does_not_say_so(): void
    {
        // Staff are the highest-value target and already have a verified email
        // route. Saying "that is a staff account" here would turn this endpoint
        // into an oracle for finding admins, so the refusal is invisible to the
        // caller and explained over SMS to the person actually holding the phone.
        $admin = User::factory()->create(['phone' => '0712345678']);
        $admin->forceFill(['type' => UserType::Admin])->save();

        $this->postJson(self::RESET, ['phone' => '0712345678'])
            ->assertOk()
            ->assertExactJson([
                'success' => 'New Password has been sent to 0712345678. Use it to login.',
            ]);

        $this->assertNull($admin->fresh()->sms_reset_password, 'no usable credential may be issued');
        $this->postJson(self::LOGIN, ['phone' => '0712345678', 'password' => UserFactory::PASSWORD])
            ->assertOk();
    }

    #[Test]
    public function one_phone_number_cannot_be_reset_over_and_over(): void
    {
        // Keyed on the NUMBER, not the caller. The route limiter keys on the
        // caller, and the caller is the attacker — a changing mobile IP gets a
        // fresh allowance every time.
        $user = User::factory()->create(['phone' => '0712345678']);

        for ($i = 0; $i < 3; $i++) {
            $this->postJson(self::RESET, ['phone' => '0712345678'])->assertOk();
        }

        $this->postJson(self::RESET, ['phone' => '0712345678'])->assertStatus(429);
    }

    /** The plaintext password out of the SMS body — the column holds only a hash. */
    private function temporaryPasswordFrom(?SendSMSJob $job): string
    {
        $this->assertNotNull($job, 'no SMS was dispatched');

        $body = (string) (new \ReflectionProperty($job, 'message'))->getValue($job);

        $this->assertSame(1, preg_match('/Use ([A-Za-z0-9]+) to sign in/', $body, $m), 'SMS body: '.$body);

        return $m[1];
    }

    #[Test]
    public function reset_password_requires_a_ten_digit_phone(): void
    {
        $response = $this->postJson(self::RESET, ['phone' => '123']);

        $response->assertStatus(400);
        $this->assertNotEmpty($response->json('errors.phone'));
        Bus::assertNotDispatched(SendSMSJob::class);
    }

    #[Test]
    public function reset_password_accepts_an_international_phone_for_a_locally_stored_user(): void
    {
        // Row is 0…; the app asks to reset with 254… — must still find the user.
        $user = User::factory()->create(['phone' => '0712345678']);

        $response = $this->postJson(self::RESET, ['phone' => '254712345678']);

        $response->assertOk();
        Bus::assertDispatched(SendSMSJob::class);
    }
}
