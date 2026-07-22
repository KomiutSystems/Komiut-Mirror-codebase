<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Enums\UserType;
use App\Http\Controllers\APIs\Auth\SocialAuthController;
use App\Models\Gender;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Laravel\Socialite\Contracts\Factory as SocialiteFactory;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Passenger-only social sign-in. Socialite is fully mocked — no provider is ever
 * contacted. A throwaway route points at the controller so the test does not
 * depend on routes/api.php (the real routes are wired separately).
 */
final class SocialAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::post('/_social/{provider}', [SocialAuthController::class, 'handle']);
    }

    private function fakeProviderUser(string $id, ?string $email, ?string $name): SocialiteUser
    {
        $user = new SocialiteUser();
        $user->map(['id' => $id, 'email' => $email, 'name' => $name]);

        return $user;
    }

    private function mockSocialite(SocialiteUser $providerUser): void
    {
        $driver = Mockery::mock();
        $driver->shouldReceive('stateless')->andReturnSelf();
        $driver->shouldReceive('userFromToken')->andReturn($providerUser);

        $factory = Mockery::mock(SocialiteFactory::class);
        $factory->shouldReceive('driver')->andReturn($driver);

        $this->app->instance(SocialiteFactory::class, $factory);
    }

    #[Test]
    public function new_passenger_is_created_and_receives_a_token(): void
    {
        $this->mockSocialite($this->fakeProviderUser('google-123', 'jane@example.com', 'Jane Doe'));

        $response = $this->postJson('/_social/google', ['access_token' => 'valid-token']);

        $response->assertOk()
            ->assertJsonStructure(['user', 'access_token', 'token_type']);

        $this->assertDatabaseHas('users', [
            'email' => 'jane@example.com',
            'type' => 'passenger',
            'provider' => 'google',
            'provider_id' => 'google-123',
            'firstname' => 'Jane',
            'lastname' => 'Doe',
        ]);
    }

    #[Test]
    public function returning_passenger_is_not_duplicated(): void
    {
        $this->mockSocialite($this->fakeProviderUser('google-123', 'jane@example.com', 'Jane Doe'));

        $this->postJson('/_social/google', ['access_token' => 't1'])->assertOk();
        $this->postJson('/_social/google', ['access_token' => 't2'])->assertOk();

        $this->assertSame(1, User::where('provider_id', 'google-123')->count());
    }

    #[Test]
    public function an_existing_non_passenger_is_refused(): void
    {
        $gender = Gender::create(['name' => 'Male', 'status' => true]);

        // A SACCO admin who happens to share the provider email.
        User::create([
            'firstname' => 'Boss',
            'lastname' => 'Admin',
            'email' => 'boss@sacco.co.ke',
            'phone' => '254700000001',
            'password' => Hash::make('secret'),
            'dob' => '1990-01-01',
            'gender_id' => $gender->id,
            'type' => UserType::Admin,
            'status' => true,
        ]);

        $this->mockSocialite($this->fakeProviderUser('google-999', 'boss@sacco.co.ke', 'Boss Admin'));

        $this->postJson('/_social/google', ['access_token' => 'valid'])
            ->assertStatus(403);

        // No token was minted and the account stays an admin.
        $this->assertDatabaseHas('users', ['email' => 'boss@sacco.co.ke', 'type' => 'admin']);
    }

    #[Test]
    public function unknown_provider_is_rejected(): void
    {
        $this->postJson('/_social/facebook', ['access_token' => 'x'])->assertStatus(422);
    }

    #[Test]
    public function missing_access_token_is_rejected(): void
    {
        $this->postJson('/_social/google', [])->assertStatus(400);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
