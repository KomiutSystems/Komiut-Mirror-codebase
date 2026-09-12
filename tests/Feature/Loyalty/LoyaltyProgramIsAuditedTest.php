<?php

declare(strict_types=1);

namespace Tests\Feature\Loyalty;

use App\Models\AuditLog;
use App\Models\LoyaltyProgram;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Queues\QueueTestCase;

/**
 * Changing a SACCO's loyalty terms is on the record, and the SACCO can read it.
 *
 * NOBODY CAN HAND POINTS TO A NAMED PASSENGER, and that is the important half of
 * this: LoyaltyService::credit() is private, reachable only from earning on a
 * paid fare, and no route exposes it. There is no "award points" endpoint for a
 * SACCO admin to abuse on behalf of a friend.
 *
 * What a SACCO admin CAN do is change the terms for everyone at once. `divisor`
 * is KES of fare per point, so dropping it from 100 to 1 makes every fare earn a
 * hundred times more; `redemption_threshold` is what a free ride costs. Neither
 * targets an individual, but both move real value, and until now they moved it
 * with no record of who did it or what it was before.
 *
 * These pin the trail AND its visibility. An audit row nobody can read is not an
 * audit trail, and one nothing asserts is one that silently stops being written.
 */
final class LoyaltyProgramIsAuditedTest extends QueueTestCase
{
    private const SAVE = '/api/auth/saccos/loyalty/save';

    private const LOG = '/api/auth/activity';

    #[Test]
    public function changing_the_terms_records_who_did_it_and_what_it_was_before(): void
    {
        $world = $this->makeWorld();
        LoyaltyProgram::withoutGlobalScopes()->create([
            'sacco_id' => $world['sacco']->id, 'is_active' => true,
            'redemption_threshold' => 500, 'divisor' => 100, 'point_value' => 0.3,
        ]);

        Sanctum::actingAs($this->makeUser(['Edit Loyalty'], $world['sacco']));

        // No point_value in the request: a dashboard that predates the field
        // must not wipe the value the SACCO already set.
        $this->postJson(self::SAVE, [
            'divisor' => 1,                 // a hundred times more generous
            'redemption_threshold' => 5,
            'is_active' => true,
        ])->assertOk()->assertJsonPath('program.point_value', 0.3);

        $row = AuditLog::where('action', 'sacco.loyalty.changed')->latest('id')->first();

        $this->assertNotNull($row, 'the only lever a SACCO has over points must leave a trace');
        $this->assertSame((int) $world['sacco']->id, (int) $row->sacco_id,
            'the row has to be attributed to the SACCO, or their own log cannot show it');
        $this->assertEqualsWithDelta(100, (float) $row->data['before']['divisor'], 0.001,
            'before matters more than after — after is visible on the program itself');
        $this->assertEqualsWithDelta(500, (float) $row->data['before']['redemption_threshold'], 0.001);
        $this->assertEqualsWithDelta(0.3, (float) $row->data['before']['point_value'], 0.001,
            'point_value moves as much real value as divisor does, so it is on the record too');
        $this->assertEqualsWithDelta(1, (float) $row->data['after']['divisor'], 0.001);
        $this->assertEqualsWithDelta(5, (float) $row->data['after']['redemption_threshold'], 0.001);
    }

    #[Test]
    public function creating_a_program_for_the_first_time_records_a_null_before(): void
    {
        // Null is meaningful here: it says the terms were SET, not changed, which
        // is a different act and the log should not imply otherwise.
        $world = $this->makeWorld();
        Sanctum::actingAs($this->makeUser(['Edit Loyalty'], $world['sacco']));

        $this->postJson(self::SAVE, ['divisor' => 100, 'redemption_threshold' => 500, 'point_value' => 0.3])->assertOk();

        $row = AuditLog::where('action', 'sacco.loyalty.changed')->latest('id')->first();

        $this->assertNotNull($row);
        $this->assertNull($row->data['before']);
    }

    #[Test]
    public function the_sacco_can_read_it_in_their_own_activity_log(): void
    {
        // The whole point: the SACCO sees who changed their loyalty terms without
        // anyone having to ask the platform.
        $world = $this->makeWorld();
        Sanctum::actingAs($this->makeUser(['Edit Loyalty', 'View Activity Log'], $world['sacco']));

        $this->postJson(self::SAVE, ['divisor' => 100, 'redemption_threshold' => 500, 'point_value' => 0.3])->assertOk();

        $actions = $this->getJson(self::LOG)->assertOk()->json('activity.*.action');

        $this->assertContains('sacco.loyalty.changed', $actions,
            'it is on the allowlist or the SACCO cannot see it at all');
    }

    #[Test]
    public function one_saccos_change_never_appears_in_anothers_log(): void
    {
        // audit_logs is not a tenant model — it carries a nullable sacco_id and
        // ActivityLogController filters on it explicitly. That filter is the only
        // thing keeping one SACCO's history out of another's screen.
        $mine = $this->makeWorld();
        $theirs = $this->makeWorld();

        Sanctum::actingAs($this->makeUser(['Edit Loyalty'], $theirs['sacco']));
        $this->postJson(self::SAVE, ['divisor' => 100, 'redemption_threshold' => 500, 'point_value' => 0.3])->assertOk();

        Sanctum::actingAs($this->makeUser(['View Activity Log'], $mine['sacco']));

        $rows = $this->getJson(self::LOG)->assertOk()->json('activity');

        $this->assertSame([], array_filter($rows, fn ($r) => $r['action'] === 'sacco.loyalty.changed'),
            "another SACCO's loyalty change must not be visible here");
    }
}
