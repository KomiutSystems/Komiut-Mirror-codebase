<?php

declare(strict_types=1);

namespace Tests\Feature\Activity;

use App\Enums\CarbonCreditType;
use App\Enums\LoyaltyTransactionType;
use App\Models\CarbonCreditTransaction;
use App\Models\LoyaltyTransaction;
use App\Models\Sacco;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Queues\QueueTestCase;

/**
 * The passenger Activity screen's unread indicator.
 *
 * The badge's job is to be believed. A badge that says "new" straight after
 * somebody has looked is worse than no badge at all — it is what teaches people
 * to ignore every badge the app will ever show them — so most of what follows is
 * about the count being zero when it should be zero, and the boundary where that
 * goes wrong.
 *
 * Covers:
 *  - ActivitySeenController (seen / unseenCount)
 *  - users.activity_seen_at, and NULL meaning "has never looked"
 */
final class ActivitySeenTest extends QueueTestCase
{
    private const SEEN = '/api/v1/auth/book_a_ride/activity/seen';

    private const COUNT = '/api/v1/auth/book_a_ride/activity/unseen-count';

    /**
     * A passenger, as passengers actually are: users.sacco_id NULL.
     *
     * That is not incidental to this suite, it is the trap. LoyaltyTransaction
     * is BelongsToSacco and SaccoScope FAILS CLOSED on a null sacco_id, so a
     * scoped count would be zero for every passenger forever — and a permanent
     * zero does not look like a bug, it looks like "nothing new".
     */
    private function passenger(): User
    {
        $user = $this->makeUser();

        $this->assertNull($user->sacco_id, 'a passenger has no home SACCO — that is the point of these tests');

        return $user;
    }

    /** One point movement. No booking and no source: a manual-adjustment shape, so the ledger's unique indexes leave it alone. */
    private function earnPoints(User $user, Sacco $sacco, float $value = 10): LoyaltyTransaction
    {
        return LoyaltyTransaction::withoutGlobalScopes()->create([
            'user_id' => $user->id,
            'sacco_id' => $sacco->id,
            'value' => $value,
            'type' => LoyaltyTransactionType::Earned,
        ]);
    }

    /** One carbon credit movement, likewise unkeyed to a booking. */
    private function earnCarbon(User $user, int $credits = 1): CarbonCreditTransaction
    {
        return CarbonCreditTransaction::create([
            'user_id' => $user->id,
            'credits' => $credits,
            'type' => CarbonCreditType::Earned,
            'spend_cents' => 0,
            'description' => 'Travelled by app',
        ]);
    }

    #[Test]
    public function marking_seen_and_asking_immediately_afterwards_returns_zero(): void
    {
        // THE test. The false positive right after somebody looks is the failure
        // the whole feature exists to avoid.
        $sacco = $this->makeSacco();
        $passenger = $this->passenger();

        $this->earnPoints($passenger, $sacco);
        $this->earnPoints($passenger, $sacco);
        $this->earnCarbon($passenger);

        Sanctum::actingAs($passenger);

        $this->getJson(self::COUNT)->assertOk()->assertJsonPath('activity.unseen.total', 3);

        $this->postJson(self::SEEN)->assertOk()->assertJsonPath('activity.unseen.total', 0);

        $this->getJson(self::COUNT)
            ->assertOk()
            ->assertJsonPath('activity.unseen.points', 0)
            ->assertJsonPath('activity.unseen.carbon_credits', 0)
            ->assertJsonPath('activity.unseen.total', 0);
    }

    #[Test]
    public function an_entry_stamped_in_the_same_second_as_the_marker_is_not_unseen(): void
    {
        // The boundary, and the reason the comparison is `>` and not `>=`.
        //
        // The marker and both ledgers' created_at are timestamp(0), and Laravel
        // binds dates as 'Y-m-d H:i:s', so everything inside one second collapses
        // onto the same stored value. A fare paid at 10:00:00.1 and the screen
        // opened at 10:00:00.4 are EQUAL on disk — with `>=` the passenger would
        // watch the badge come straight back.
        $sacco = $this->makeSacco();
        $passenger = $this->passenger();
        Sanctum::actingAs($passenger);

        $this->travelTo(Carbon::parse('2026-09-08 10:00:00.100000'));
        $this->earnPoints($passenger, $sacco);              // stored 10:00:00

        $this->travelTo(Carbon::parse('2026-09-08 10:00:00.400000'));
        $this->postJson(self::SEEN)->assertOk();            // marker 10:00:00

        // And the other half of the same second, landing after the mark: still
        // the second they were looking at the screen, so still not "new".
        $this->travelTo(Carbon::parse('2026-09-08 10:00:00.900000'));
        $this->earnCarbon($passenger);                      // stored 10:00:00

        // The zero below has to be a real zero, not an empty ledger.
        $this->assertSame('2026-09-08 10:00:00', $passenger->fresh()->activity_seen_at->toDateTimeString());
        $this->assertSame(1, LoyaltyTransaction::withoutGlobalScopes()->where('user_id', $passenger->id)->count());
        $this->assertSame(1, CarbonCreditTransaction::where('user_id', $passenger->id)->count());

        $this->getJson(self::COUNT)
            ->assertOk()
            ->assertJsonPath('activity.unseen.points', 0)
            ->assertJsonPath('activity.unseen.carbon_credits', 0)
            ->assertJsonPath('activity.unseen.total', 0);

        $this->travelBack();
    }

    #[Test]
    public function an_entry_in_the_next_second_is_unseen(): void
    {
        // The other side of the boundary — the badge still has to work.
        $sacco = $this->makeSacco();
        $passenger = $this->passenger();
        Sanctum::actingAs($passenger);

        $this->travelTo(Carbon::parse('2026-09-08 10:00:00.400000'));
        $this->postJson(self::SEEN)->assertOk();

        $this->travelTo(Carbon::parse('2026-09-08 10:00:01.000000'));
        $this->earnPoints($passenger, $sacco);
        $this->earnCarbon($passenger);

        $this->getJson(self::COUNT)
            ->assertOk()
            ->assertJsonPath('activity.unseen.points', 1)
            ->assertJsonPath('activity.unseen.carbon_credits', 1)
            ->assertJsonPath('activity.unseen.total', 2);

        $this->travelBack();
    }

    #[Test]
    public function an_entry_from_before_the_marker_is_never_unseen(): void
    {
        $sacco = $this->makeSacco();
        $passenger = $this->passenger();
        Sanctum::actingAs($passenger);

        $this->travelTo(Carbon::parse('2026-09-08 09:59:59.900000'));
        $this->earnPoints($passenger, $sacco);              // stored 09:59:59

        $this->travelTo(Carbon::parse('2026-09-08 10:00:00.100000'));
        $this->postJson(self::SEEN)->assertOk();            // marker 10:00:00

        $this->assertSame(1, LoyaltyTransaction::withoutGlobalScopes()->where('user_id', $passenger->id)->count());
        $this->getJson(self::COUNT)->assertOk()->assertJsonPath('activity.unseen.total', 0);

        $this->travelBack();
    }

    #[Test]
    public function a_passenger_who_has_never_looked_sees_everything(): void
    {
        // NULL means "has never looked", NOT "nothing new". Read the other way
        // round, every account that existed before this shipped would open the
        // app to an empty badge over a history it has never been shown.
        $sacco = $this->makeSacco();
        $passenger = $this->passenger();

        $this->earnPoints($passenger, $sacco);
        $this->earnPoints($passenger, $sacco);
        $this->earnPoints($passenger, $sacco);
        $this->earnCarbon($passenger);
        $this->earnCarbon($passenger);

        $this->assertNull($passenger->fresh()->activity_seen_at);

        Sanctum::actingAs($passenger);
        $this->getJson(self::COUNT)
            ->assertOk()
            ->assertJsonPath('activity.seen_at', null)
            ->assertJsonPath('activity.unseen.points', 3)
            ->assertJsonPath('activity.unseen.carbon_credits', 2)
            ->assertJsonPath('activity.unseen.total', 5);
    }

    #[Test]
    public function points_and_carbon_are_counted_apart_as_well_as_together(): void
    {
        // The app may badge the two feeds together or separately; it should not
        // have to work out either number for itself.
        $sacco = $this->makeSacco();
        $passenger = $this->passenger();
        Sanctum::actingAs($passenger);

        $this->travelTo(Carbon::parse('2026-09-08 10:00:00'));
        $this->postJson(self::SEEN)->assertOk();

        $this->travelTo(Carbon::parse('2026-09-08 11:00:00'));
        $this->earnPoints($passenger, $sacco);
        $this->earnPoints($passenger, $sacco);
        $this->earnCarbon($passenger);

        $this->getJson(self::COUNT)
            ->assertOk()
            ->assertJsonPath('activity.unseen.points', 2)
            ->assertJsonPath('activity.unseen.carbon_credits', 1)
            ->assertJsonPath('activity.unseen.total', 3);

        $this->travelBack();
    }

    #[Test]
    public function two_people_sharing_one_handset_do_not_share_a_marker(): void
    {
        // Crews and families share phones. This is the case a device-local flag
        // gets wrong: the driver looks, and his wife's badge clears with his.
        $sacco = $this->makeSacco();
        $him = $this->passenger();
        $her = $this->passenger();

        $this->earnPoints($him, $sacco);
        $this->earnPoints($her, $sacco);
        $this->earnCarbon($her);

        Sanctum::actingAs($him);
        $this->postJson(self::SEEN)->assertOk()->assertJsonPath('activity.unseen.total', 0);

        // Same handset, her account: her own two entries, still unseen.
        Sanctum::actingAs($her);
        $this->getJson(self::COUNT)
            ->assertOk()
            ->assertJsonPath('activity.seen_at', null)
            ->assertJsonPath('activity.unseen.total', 2);

        $this->assertNull($her->fresh()->activity_seen_at);
        $this->assertNotNull($him->fresh()->activity_seen_at);
    }

    #[Test]
    public function another_passengers_activity_is_never_counted(): void
    {
        $sacco = $this->makeSacco();
        $mine = $this->passenger();
        $theirs = $this->passenger();

        $this->earnPoints($theirs, $sacco);
        $this->earnPoints($theirs, $sacco);
        $this->earnCarbon($theirs);

        Sanctum::actingAs($mine);
        $this->getJson(self::COUNT)->assertOk()->assertJsonPath('activity.unseen.total', 0);
    }

    #[Test]
    public function seen_answers_with_the_marker_it_stored(): void
    {
        // The client is told to trust this response and clear the badge without
        // a refetch, so the timestamp in it has to be the one on disk — not a
        // pre-truncation now() that disagrees with the column by a fraction.
        $passenger = $this->passenger();
        Sanctum::actingAs($passenger);

        $this->travelTo(Carbon::parse('2026-09-08 10:00:00.750000'));

        $this->postJson(self::SEEN)
            ->assertOk()
            ->assertExactJson([
                'activity' => [
                    'seen_at' => '2026-09-08T10:00:00+00:00',
                    'unseen' => ['points' => 0, 'carbon_credits' => 0, 'total' => 0],
                ],
            ]);

        $this->assertSame(
            '2026-09-08 10:00:00',
            $passenger->fresh()->activity_seen_at->toDateTimeString()
        );

        $this->travelBack();
    }

    #[Test]
    public function looking_at_a_screen_is_not_an_edit_to_the_account(): void
    {
        // updated_at has to keep meaning "this record was changed" — the same
        // reason TouchLastActive writes through the query builder.
        $passenger = $this->passenger();
        DB::table('users')->where('id', $passenger->id)->update(['updated_at' => '2020-01-01 00:00:00']);

        Sanctum::actingAs($passenger);
        $this->postJson(self::SEEN)->assertOk();

        $this->assertSame('2020-01-01 00:00:00', $passenger->fresh()->updated_at->toDateTimeString());
    }

    #[Test]
    public function marking_seen_again_moves_the_marker_forward(): void
    {
        $sacco = $this->makeSacco();
        $passenger = $this->passenger();
        Sanctum::actingAs($passenger);

        $this->travelTo(Carbon::parse('2026-09-08 10:00:00'));
        $this->postJson(self::SEEN)->assertOk();

        $this->travelTo(Carbon::parse('2026-09-08 11:00:00'));
        $this->earnPoints($passenger, $sacco);
        $this->getJson(self::COUNT)->assertOk()->assertJsonPath('activity.unseen.total', 1);

        $this->travelTo(Carbon::parse('2026-09-08 12:00:00'));
        $this->postJson(self::SEEN)->assertOk()->assertJsonPath('activity.seen_at', '2026-09-08T12:00:00+00:00');
        $this->getJson(self::COUNT)->assertOk()->assertJsonPath('activity.unseen.total', 0);

        $this->travelBack();
    }

    #[Test]
    public function both_url_prefixes_reach_the_same_endpoints(): void
    {
        // routes/api.php registers the mobile group under `auth` and `v1/auth`;
        // the app is mid-migration between them and both have to work.
        $sacco = $this->makeSacco();
        $passenger = $this->passenger();
        $this->earnPoints($passenger, $sacco);

        Sanctum::actingAs($passenger);

        $this->getJson('/api/auth/book_a_ride/activity/unseen-count')
            ->assertOk()
            ->assertJsonPath('activity.unseen.total', 1);

        $this->postJson('/api/auth/book_a_ride/activity/seen')->assertOk();

        $this->getJson('/api/v1/auth/book_a_ride/activity/unseen-count')
            ->assertOk()
            ->assertJsonPath('activity.unseen.total', 0);
    }

    #[Test]
    public function neither_endpoint_answers_an_unauthenticated_caller(): void
    {
        // The marker is a per-person fact; there is no such thing as an
        // anonymous one. (And a controller in this route group without
        // auth:sanctum in its constructor is the bug that signed passengers out
        // of production.)
        $this->postJson(self::SEEN)->assertUnauthorized();
        $this->getJson(self::COUNT)->assertUnauthorized();
    }
}
