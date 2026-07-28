<?php

declare(strict_types=1);

namespace Tests\Feature\Sacco;

use App\Enums\SaccoClaimStatus;
use App\Models\Sacco;
use App\Services\Sacco\SaccoDirectory;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Queues\QueueTestCase;

/**
 * The SACCO directory: names drivers attach to at onboarding, before their
 * SACCO has an account. Nothing here creates a login.
 */
final class SaccoDirectoryTest extends QueueTestCase
{
    private const ENDPOINT = '/api/v1/auth/saccos/directory';

    /** A directory row on the test brand, since these tests bypass makeSacco's naming. */
    private function directoryEntry(string $name, ?string $email = null): Sacco
    {
        return Sacco::create([
            'name' => $name,
            'email' => $email,
            'phone' => '0700' . str_pad((string) $this->nextSequence(), 6, '0', STR_PAD_LEFT),
            'status' => 1,
            'brand' => 'testing',
        ]);
    }

    #[Test]
    public function it_returns_matching_saccos_by_name(): void
    {
        $this->directoryEntry('Nicco SACCO', 'ops@nicco.test');
        $this->directoryEntry('Super Metro');

        $response = $this->getJson(self::ENDPOINT . '?q=nic');

        $response->assertOk();
        $this->assertSame(['Nicco SACCO'], array_column($response->json('saccos'), 'name'));
    }

    #[Test]
    public function it_never_exposes_sacco_contact_details(): void
    {
        $this->directoryEntry('Nicco SACCO', 'ops@nicco.test');

        $response = $this->getJson(self::ENDPOINT . '?q=nicco');

        // The exact key set, not just "email is absent": anything the model gains
        // later must be opted in, since this endpoint is unauthenticated.
        $this->assertSame(['id', 'name'], array_keys($response->json('saccos.0')));
    }

    #[Test]
    public function it_requires_at_least_two_characters(): void
    {
        $this->directoryEntry('Nicco SACCO');

        $this->getJson(self::ENDPOINT . '?q=n')->assertOk()->assertExactJson(['saccos' => []]);
        $this->getJson(self::ENDPOINT)->assertOk()->assertExactJson(['saccos' => []]);
    }

    #[Test]
    public function resolve_or_submit_returns_the_existing_sacco(): void
    {
        Context::add('brand', 'testing');
        $existing = $this->directoryEntry('Nicco SACCO');

        $resolved = $this->directory()->resolveOrSubmit('  nicco sacco ');

        $this->assertSame($existing->id, $resolved->id);
        $this->assertSame(1, Sacco::count());
    }

    #[Test]
    public function resolve_or_submit_records_an_unknown_name_for_review(): void
    {
        Context::add('brand', 'testing');

        $sacco = $this->directory()->resolveOrSubmit('Super Metro');

        $this->assertSame(SaccoClaimStatus::PendingReview, $sacco->claim_status);
        $this->assertSame('driver_submitted', $sacco->source);
        $this->assertSame(1, (int) $sacco->status);
        // A directory entry is a name, never an account.
        $this->assertNull($sacco->email);
        $this->assertDatabaseHas('saccos', ['name' => 'Super Metro', 'claim_status' => 'pending_review']);
    }

    #[Test]
    public function the_backfill_claims_only_saccos_that_already_had_an_account(): void
    {
        $selfRegistered = $this->directoryEntry('Nicco SACCO', 'ops@nicco.test');
        $listedOnly = $this->directoryEntry('Super Metro');

        // RefreshDatabase migrates an empty database, so the backfill had nothing
        // to classify. Reset both rows to the column default and re-run it.
        DB::table('saccos')->update(['claim_status' => 'directory', 'source' => null]);
        $this->migration()->backfill();

        $this->assertDatabaseHas('saccos', [
            'id' => $selfRegistered->id, 'claim_status' => 'claimed', 'source' => 'self_registered',
        ]);
        $this->assertDatabaseHas('saccos', [
            'id' => $listedOnly->id, 'claim_status' => 'directory', 'source' => null,
        ]);
    }

    #[Test]
    public function a_deactivated_sacco_is_hidden_from_the_picker(): void
    {
        $sacco = $this->makeSacco();
        $name = $sacco->name;

        $this->assertCount(1, $this->directory()->search($name));

        // Deactivating is the reversible way to retire a bad or duplicate entry;
        // without it the only remedy is deletion, which takes members with it.
        $sacco->forceFill(['status' => 0])->save();

        $this->assertCount(0, $this->directory()->search($name));
    }

    private function directory(): SaccoDirectory
    {
        return app(SaccoDirectory::class);
    }

    private function migration(): object
    {
        return require database_path('migrations/2026_07_27_120000_add_directory_fields_to_saccos_table.php');
    }
}
