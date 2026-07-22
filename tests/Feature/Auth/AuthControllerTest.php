<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

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
        $response->assertExactJson(['error' => 'Invalid username/password']);
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
        $response->assertExactJson(['error' => 'Invalid username/password']);
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
    public function login_with_invalid_credentials_returns_401(): void
    {
        $user = User::factory()->create();

        $response = $this->postJson(self::LOGIN, [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

        $response->assertStatus(401);
        $response->assertExactJson(['error' => 'Invalid username/password']);
    }

    #[Test]
    public function login_with_an_unknown_email_returns_401(): void
    {
        $response = $this->postJson(self::LOGIN, [
            'email' => 'nobody@example.com',
            'password' => 'whatever',
        ]);

        $response->assertStatus(401);
        $response->assertExactJson(['error' => 'Invalid username/password']);
    }

    #[Test]
    public function login_without_a_password_fails_validation_with_400(): void
    {
        $response = $this->postJson(self::LOGIN, [
            'email' => 'someone@example.com',
        ]);

        $response->assertStatus(400);
        $response->assertJsonPath('errors.password.0', 'The password field is required.');
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
        $response->assertJsonStructure([
            'errors' => ['firstname', 'lastname', 'email', 'phone', 'password', 'gender'],
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
        $response->assertExactJson(['error' => 'Provided phone not found!']);
        Bus::assertNotDispatched(SendSMSJob::class);
    }

    #[Test]
    public function reset_password_regenerates_the_password_and_dispatches_the_sms_job(): void
    {
        $user = User::factory()->create(['phone' => '0712345678']);
        $originalHash = $user->getAuthPassword();

        $response = $this->postJson(self::RESET, ['phone' => $user->phone]);

        $response->assertOk();
        $response->assertExactJson([
            'success' => 'New Password has been sent to 0712345678. Use it to login.',
        ]);

        Bus::assertDispatched(SendSMSJob::class);

        $user->refresh();
        $this->assertNotSame($originalHash, $user->password);
        $this->assertFalse(Hash::check(UserFactory::PASSWORD, $user->password));
    }

    #[Test]
    public function reset_password_requires_a_ten_digit_phone(): void
    {
        $response = $this->postJson(self::RESET, ['phone' => '123']);

        $response->assertStatus(400);
        $this->assertNotEmpty($response->json('errors.phone'));
        Bus::assertNotDispatched(SendSMSJob::class);
    }
}
