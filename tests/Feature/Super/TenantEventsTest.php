<?php

declare(strict_types=1);

namespace Tests\Feature\Super;

use App\Enums\SaccoClaimStatus;
use App\Enums\UserType;
use App\Models\AuditLog;
use App\Models\PlatformNotification;
use App\Models\Sacco;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Queues\QueueTestCase;

/**
 * Tenant-lifecycle domain: the SACCO observer's emitted console events and the
 * review-queue read endpoint. Counts are asserted by event name, never by the
 * global notification total — makeSacco() defaults to a directory row, so every
 * created SACCO in the suite already emits sacco.pending_review.created.
 */
final class TenantEventsTest extends QueueTestCase
{
    private function superAdmin(): User
    {
        $user = $this->makeUser();
        $user->forceFill(['type' => UserType::Superadmin])->save();
        Permission::findOrCreate('View Platform Notifications', 'web');
        $user->givePermissionTo('View Platform Notifications');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user;
    }

    #[Test]
    public function creating_a_pending_sacco_emits_pending_review(): void
    {
        $sacco = $this->makeSacco(); // claim_status defaults to 'directory'

        $row = PlatformNotification::where('event', 'sacco.pending_review.created')
            ->where('brand', 'testing')
            ->first();

        $this->assertNotNull($row, 'A directory SACCO should enter the review queue.');
        $this->assertSame('review', $row->delivery_class);
        $this->assertSame('normal', $row->severity);
        $this->assertSame($sacco->id, $row->data['saccoId']);
        $this->assertSame($sacco->name, $row->data['name']);
    }

    #[Test]
    public function flipping_a_sacco_status_emits_status_changed_with_an_audit_row(): void
    {
        $sacco = $this->makeSacco(); // status = 1

        $sacco->update(['status' => 0]);

        $row = PlatformNotification::where('event', 'sacco.status.changed')->first();
        $this->assertNotNull($row, 'A status flip must emit sacco.status.changed.');
        $this->assertSame('alert', $row->delivery_class);
        $this->assertSame(1, $row->data['from']);
        $this->assertSame(0, $row->data['to']);

        // AUDIT-FIRST: the notification references an audit_logs row.
        $this->assertNotNull($row->audit_id);
        $audit = AuditLog::find($row->audit_id);
        $this->assertNotNull($audit);
        $this->assertSame('sacco.status.changed', $audit->action);
    }

    #[Test]
    public function claiming_a_sacco_emits_claimed_with_inherited_counts(): void
    {
        $sacco = $this->makeSacco();
        // Two drivers already attached on the ground before the claim.
        $this->makeUser([], $sacco);
        $this->makeUser([], $sacco);

        // Reload so the model's original reflects the persisted 'directory' default,
        // mirroring the real find-then-save claim flow.
        $sacco->refresh();
        $sacco->update(['claim_status' => SaccoClaimStatus::Claimed]);

        $row = PlatformNotification::where('event', 'sacco.claimed')->first();
        $this->assertNotNull($row);
        $this->assertSame('directory', $row->data['previousClaimStatus']);
        $this->assertSame(2, $row->data['inheritedDriverCount']);
        $this->assertNotNull($row->audit_id, 'sacco.claimed is audit-first.');
    }

    #[Test]
    public function the_review_queue_endpoint_returns_review_class_rows(): void
    {
        $this->makeSacco(); // emits a review-class pending_review row
        Sanctum::actingAs($this->superAdmin());

        $this->getJson('/api/v1/super/tenant/review-queue')
            ->assertOk()
            ->assertJsonPath('message.items.0.class', 'review')
            ->assertJsonPath('message.items.0.event', 'sacco.pending_review.created');
    }

    #[Test]
    public function the_review_queue_hides_resolved_rows(): void
    {
        $this->makeSacco();
        PlatformNotification::query()->update(['resolved_at' => now()]);

        Sanctum::actingAs($this->superAdmin());

        $this->getJson('/api/v1/super/tenant/review-queue')
            ->assertOk()
            ->assertJsonPath('message.totalCount', 0);
    }

    #[Test]
    public function the_audit_endpoint_lists_sacco_actions(): void
    {
        $sacco = $this->makeSacco();
        $sacco->update(['status' => 0]);

        Sanctum::actingAs($this->superAdmin());

        $this->getJson('/api/v1/super/tenant/audit')
            ->assertOk()
            ->assertJsonPath('message.items.0.action', 'sacco.status.changed');
    }
}
