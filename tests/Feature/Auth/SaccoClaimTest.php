<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Enums\SaccoClaimStatus;
use App\Models\Sacco;
use App\Models\User;
use App\Services\Sacco\SaccoDirectory;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Context;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Claiming a directory entry.
 *
 * Drivers are onboarded under a SACCO long before it registers, which leaves an
 * unclaimed directory row holding that name. Self-registration must therefore
 * CLAIM that row rather than collide with it — keeping its id, so the drivers
 * already attached become members of the real SACCO the moment it signs up.
 */
final class SaccoClaimTest extends TestCase
{
    use RefreshDatabase;

    private const REGISTER_SACCO = '/api/auth/register/sacco';

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
        $this->seed(RoleSeeder::class);
    }

    private function register(string $name, string $email): \Illuminate\Testing\TestResponse
    {
        return $this->postJson(self::REGISTER_SACCO, [
            'name' => $name,
            'email' => $email,
            'phone' => '0700' . random_int(100000, 999999),
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
        ]);
    }

    #[Test]
    public function registering_claims_an_existing_directory_entry_and_keeps_its_drivers(): void
    {
        // A field agent onboarded a driver under a SACCO nobody had registered.
        // Onboarding always runs inside a branded request, so mirror that here —
        // an entry with no brand would be invisible to the branded lookup.
        Context::add('brand', 'testing');
        $entry = app(SaccoDirectory::class)->resolveOrSubmit('Nicco SACCO');
        $driver = User::create([
            'firstname' => 'Test', 'lastname' => 'Driver',
            'email' => 'driver@example.test', 'phone' => '0711000111',
            'password' => 'password', 'sacco_id' => $entry->id, 'status' => true,
        ]);

        $this->register('Nicco SACCO', 'admin@nicco.co.ke')->assertOk();

        // Same row, now claimed — not a second SACCO with a duplicate name.
        $this->assertSame(1, Sacco::withoutGlobalScopes()->where('name', 'Nicco SACCO')->count());
        $claimed = $entry->fresh();
        $this->assertSame(SaccoClaimStatus::Claimed, $claimed->claim_status);
        $this->assertSame('admin@nicco.co.ke', $claimed->email);
        $this->assertNotNull($claimed->verified_at);

        // The driver onboarded earlier now belongs to the registered SACCO.
        $this->assertSame($entry->id, $driver->fresh()->sacco_id);
    }

    #[Test]
    public function a_fresh_registration_is_marked_claimed(): void
    {
        $this->register('Brand New SACCO', 'admin@brandnew.co.ke')->assertOk();

        $sacco = Sacco::withoutGlobalScopes()->where('name', 'Brand New SACCO')->firstOrFail();
        $this->assertSame(SaccoClaimStatus::Claimed, $sacco->claim_status);
        $this->assertSame('self_registered', $sacco->source);
    }

    #[Test]
    public function an_already_claimed_name_cannot_be_registered_twice(): void
    {
        $this->register('Umoja SACCO', 'first@umoja.co.ke')->assertOk();

        $this->register('Umoja SACCO', 'second@umoja.co.ke')
            ->assertStatus(400)
            ->assertJsonStructure(['errors' => ['name']]);

        $this->assertSame(1, Sacco::withoutGlobalScopes()->where('name', 'Umoja SACCO')->count());
    }
}
