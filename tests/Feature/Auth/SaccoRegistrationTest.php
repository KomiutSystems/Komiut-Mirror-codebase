<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Auth\Roles;
use App\Enums\UserType;
use App\Models\Sacco;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * SACCO self-registration (App\Http\Controllers\APIs\AuthController@registerSacco):
 * creates the SACCO + its first admin from SACCO name/email (no personal names),
 * logs them in, and lets them sign back in with the same email + password.
 */
final class SaccoRegistrationTest extends TestCase
{
    use RefreshDatabase;

    private const string REGISTER_SACCO = '/api/auth/register/sacco';
    private const string LOGIN = '/api/auth/login';

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
        $this->seed(RoleSeeder::class); // so the assigned SACCO Admin role carries permissions
    }

    #[Test]
    public function it_registers_a_sacco_with_an_admin_and_returns_a_token(): void
    {
        $response = $this->postJson(self::REGISTER_SACCO, [
            'name' => 'Umoja SACCO',
            'email' => 'admin@umoja.co.ke',
            'phone' => '0700111222',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
        ]);

        $response->assertOk();
        $this->assertNotEmpty($response->json('access_token'));
        $this->assertSame('bearer', $response->json('token_type'));

        $sacco = Sacco::withoutGlobalScopes()->where('name', 'Umoja SACCO')->first();
        $this->assertNotNull($sacco);
        $this->assertSame('admin@umoja.co.ke', $sacco->email);
        $this->assertEquals(1, (int) $sacco->status);

        $user = User::where('email', 'admin@umoja.co.ke')->first();
        $this->assertNotNull($user);
        $this->assertSame($sacco->id, $user->sacco_id);
        $this->assertSame(UserType::Admin, $user->type);
        $this->assertTrue($user->hasRole(Roles::SACCO_ADMIN));

        // Guard-resolution check: the login payload's permissions must actually
        // resolve for the sanctum user (the classic spatie failure is perms
        // seeded under one guard, resolved under another → empty array).
        $permissions = $response->json('permissions');
        $this->assertNotEmpty($permissions);
        $this->assertContains('View Dashboard', $permissions);
        // A SACCO admin must never receive platform-only permissions.
        $this->assertNotContains('Add Saccos', $permissions);
    }

    #[Test]
    public function the_new_sacco_admin_can_log_in(): void
    {
        $this->postJson(self::REGISTER_SACCO, [
            'name' => 'Kenya Mpya',
            'email' => 'ops@kenyampya.co.ke',
            'phone' => '0711222333',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
        ])->assertOk();

        $login = $this->postJson(self::LOGIN, [
            'email' => 'ops@kenyampya.co.ke',
            'password' => 'secret123',
        ]);

        $login->assertOk();
        $this->assertNotEmpty($login->json('access_token'));
    }

    #[Test]
    public function it_rejects_a_duplicate_sacco_name_or_email(): void
    {
        $payload = [
            'name' => 'Forward Travellers',
            'email' => 'admin@forward.co.ke',
            'phone' => '0722333444',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
        ];
        $this->postJson(self::REGISTER_SACCO, $payload)->assertOk();
        $this->postJson(self::REGISTER_SACCO, $payload)->assertStatus(400);

        $this->assertSame(1, Sacco::withoutGlobalScopes()->where('name', 'Forward Travellers')->count());
    }

    #[Test]
    public function it_requires_sacco_name_email_phone_and_password(): void
    {
        $response = $this->postJson(self::REGISTER_SACCO, []);

        $response->assertStatus(400);
        $response->assertJsonStructure(['errors' => ['name', 'email', 'phone', 'password']]);
    }
}
