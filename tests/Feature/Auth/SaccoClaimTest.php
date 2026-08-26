<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Enums\SaccoClaimStatus;
use App\Models\Sacco;
use App\Models\User;
use App\Services\Sacco\SaccoDirectory;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Context;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Queues\QueueTestCase;

/**
 * Claiming a directory entry at POST auth/register/sacco.
 *
 * Drivers are onboarded under a SACCO long before it registers, which leaves an
 * unclaimed directory row holding that name. Self-registration therefore CLAIMS
 * that row rather than colliding with it — keeping its id, so whatever is
 * already attached comes along.
 *
 * THAT IS ALSO THE ATTACK. The endpoint is public and unauthenticated, the names
 * are readable from the public type-ahead on this same service, and the claimer
 * is made SACCO Admin on the row they name. So "keeps its id" cuts both ways:
 * measured on production the day this gate was written, 48 rows were claimable
 * and 45 had substance behind them — one had 180 vehicles taking KES 124,000
 * that day. Anyone could have typed its name and taken it.
 *
 * The rule is SUBSTANCE, not provenance. Nothing attached means nothing to
 * steal, and claiming is exactly what the flow is for. Anything attached means
 * it is somebody's business and a human has to be in the loop.
 *
 * WHAT THIS COSTS. A SACCO whose drivers were onboarded by a field agent can no
 * longer self-register and pick them up — it needs an operator. That was a
 * deliberate trade: of the 45 populated rows, 44 had vehicles and the single
 * users-only row was a demo account, so no real operator was waiting on the
 * self-service path, and the refusal tells them who to contact.
 */
final class SaccoClaimTest extends QueueTestCase
{
    private const REGISTER_SACCO = '/api/auth/register/sacco';

    protected function setUp(): void
    {
        parent::setUp();

        // Onboarding always runs inside a branded request; mirror that, or a
        // directory entry with no brand is invisible to the branded lookup.
        Context::add('brand', 'testing');
        $this->seed(RoleSeeder::class);
    }

    private function register(string $name, string $email): TestResponse
    {
        $n = $this->nextSequence();

        return $this->postJson(self::REGISTER_SACCO, [
            'name' => $name,
            'email' => $email,
            'phone' => '07'.str_pad((string) $n, 8, '0', STR_PAD_LEFT),
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
        ]);
    }

    #[Test]
    public function registering_claims_an_empty_directory_entry_and_keeps_its_id(): void
    {
        // The case the feature exists for, and the reason this is a substance
        // test rather than a blanket refusal. If this breaks, self-registration
        // is dead and every new SACCO needs an operator.
        $entry = app(SaccoDirectory::class)->resolveOrSubmit('Nicco SACCO');

        $this->register('Nicco SACCO', 'admin@nicco.co.ke')->assertOk();

        // Same row, now claimed — not a second SACCO with a duplicate name.
        $this->assertSame(1, Sacco::withoutGlobalScopes()->where('name', 'Nicco SACCO')->count());

        $claimed = $entry->fresh();
        $this->assertSame(SaccoClaimStatus::Claimed, $claimed->claim_status);
        $this->assertSame('admin@nicco.co.ke', $claimed->email);
        $this->assertNotNull($claimed->verified_at);
    }

    #[Test]
    public function a_sacco_with_vehicles_cannot_be_claimed_from_the_internet(): void
    {
        // The production case: a real operator's fleet sitting behind a row
        // nobody had claimed yet.
        $world = $this->makeWorld();
        $victim = $world['sacco'];
        $victim->forceFill(['claim_status' => SaccoClaimStatus::PendingReview])->save();

        $this->register($victim->name, 'attacker@example.test')->assertStatus(400);

        $this->assertSame(
            SaccoClaimStatus::PendingReview,
            $victim->fresh()->claim_status,
            'a populated SACCO must not change hands'
        );
        $this->assertNull(
            User::withoutGlobalScopes()->where('email', 'attacker@example.test')->first(),
            'the refused claim must not leave an admin account behind'
        );
    }

    #[Test]
    public function a_sacco_with_drivers_cannot_be_claimed_from_the_internet(): void
    {
        // Vehicles are not the only substance. A SACCO mid-onboarding may have
        // drivers and no bus registered yet, and those drivers' records — phone,
        // ID, licence, earnings — are just as much somebody's business.
        $entry = app(SaccoDirectory::class)->resolveOrSubmit('Has Drivers SACCO');
        $this->makeUser([], $entry);

        $this->register('Has Drivers SACCO', 'attacker@example.test')->assertStatus(400);

        $this->assertSame(SaccoClaimStatus::PendingReview, $entry->fresh()->claim_status);
    }

    #[Test]
    public function a_populated_row_is_told_to_get_verified_not_to_ask_a_nonexistent_admin(): void
    {
        // The refusal a REAL operator hits. Nobody has registered this SACCO, so
        // "ask its admin" names a person who does not exist — and the one human
        // who could fix it would read that as the platform losing their account.
        $entry = app(SaccoDirectory::class)->resolveOrSubmit('Unregistered Real SACCO');
        $this->makeUser([], $entry);

        $message = $this->register('Unregistered Real SACCO', 'boss@real.co.ke')
            ->assertStatus(400)
            ->json('errors.name.0');

        $this->assertStringContainsString('verify', $message);
        $this->assertStringNotContainsString('Ask its admin', $message);
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

        $message = $this->register('Umoja SACCO', 'second@umoja.co.ke')
            ->assertStatus(400)
            ->assertJsonStructure(['errors' => ['name']])
            ->json('errors.name.0');

        // This one DOES have an admin, so this is the message that helps.
        $this->assertStringContainsString('Ask its admin', $message);
        $this->assertSame(1, Sacco::withoutGlobalScopes()->where('name', 'Umoja SACCO')->count());
    }

    #[Test]
    public function the_claim_endpoint_is_rate_limited(): void
    {
        // Substance is the wall; the throttle is what stops someone walking the
        // whole directory looking for the rows that are genuinely empty.
        foreach (['api/auth/register/sacco', 'api/v1/auth/register/sacco'] as $uri) {
            $route = collect(app('router')->getRoutes()->getRoutes())
                ->first(fn ($r) => $r->uri() === $uri);

            $this->assertNotNull($route, $uri.' must exist');
            $this->assertContains(
                'throttle:5,1',
                $route->gatherMiddleware(),
                $uri.' must be throttled'
            );
        }
    }
}
