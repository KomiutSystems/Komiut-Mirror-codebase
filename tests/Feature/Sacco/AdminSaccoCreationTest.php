<?php

declare(strict_types=1);

namespace Tests\Feature\Sacco;

use App\Enums\SaccoClaimStatus;
use App\Models\Sacco;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Queues\QueueTestCase;

/**
 * Admin-created SACCOs (App\Http\Controllers\APIs\Dashboard\Saccos\SaccoAPIController@addSacco).
 *
 * An admin adding a name is populating the DIRECTORY, not registering a tenant:
 * the row gets `source = manual` but stays at the `directory` claim status,
 * because nobody has claimed it. That endpoint doubles as the edit path, so the
 * stamp must happen on creation only — an edit must never rewrite the origin of
 * a row that came from SASRA, a driver, or self-registration.
 */
final class AdminSaccoCreationTest extends QueueTestCase
{
    private const ENDPOINT = '/api/auth/saccos/add';

    /** @param array<string, mixed> $overrides */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'id' => 0,
            'name' => 'Registrar Listed SACCO',
            'slogan' => 'On time, every time',
            'phone' => '0700123456',
            'status' => '1',
        ], $overrides);
    }

    private function actAsSaccoAdmin(): void
    {
        Sanctum::actingAs($this->makeUser(['Add Saccos', 'Edit Saccos']));
    }

    #[Test]
    public function an_admin_created_sacco_is_a_manual_directory_entry(): void
    {
        $this->actAsSaccoAdmin();

        $this->postJson(self::ENDPOINT, $this->payload())->assertOk();

        $sacco = Sacco::withoutGlobalScopes()->where('name', 'Registrar Listed SACCO')->firstOrFail();
        $this->assertSame('manual', $sacco->source);
        // An admin adding a name is not the SACCO claiming it.
        $this->assertSame(SaccoClaimStatus::Directory, $sacco->claim_status);
        $this->assertNull($sacco->verified_at);
    }

    #[Test]
    public function editing_a_sacco_does_not_rewrite_where_it_came_from(): void
    {
        $existing = Sacco::create([
            'name' => 'Driver Submitted SACCO',
            'phone' => '0700999888',
            'status' => 1,
            'brand' => 'testing',
            'claim_status' => SaccoClaimStatus::PendingReview,
            'source' => 'driver_submitted',
        ]);

        $this->actAsSaccoAdmin();

        $this->postJson(self::ENDPOINT, $this->payload([
            'id' => $existing->id,
            'name' => 'Driver Submitted SACCO (corrected)',
        ]))->assertOk();

        $existing->refresh();
        $this->assertSame('Driver Submitted SACCO (corrected)', $existing->name);
        // The edit renamed the row; it did not relabel its origin or its status.
        $this->assertSame('driver_submitted', $existing->source);
        $this->assertSame(SaccoClaimStatus::PendingReview, $existing->claim_status);
    }

    #[Test]
    public function editing_a_claimed_sacco_leaves_it_self_registered(): void
    {
        $claimed = Sacco::create([
            'name' => 'Umoja SACCO',
            'email' => 'admin@umoja.co.ke',
            'phone' => '0700777666',
            'status' => 1,
            'brand' => 'testing',
            'claim_status' => SaccoClaimStatus::Claimed,
            'source' => 'self_registered',
        ]);

        $this->actAsSaccoAdmin();

        $this->postJson(self::ENDPOINT, $this->payload([
            'id' => $claimed->id,
            'name' => 'Umoja SACCO',
        ]))->assertOk();

        $claimed->refresh();
        $this->assertSame('self_registered', $claimed->source);
        $this->assertSame(SaccoClaimStatus::Claimed, $claimed->claim_status);
    }
}
