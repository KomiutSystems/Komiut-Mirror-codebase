<?php

declare(strict_types=1);

namespace Tests\Feature\Activity;

use App\Enums\CarbonCreditType;
use App\Enums\LoyaltyTransactionType;
use App\Events\PassengerBalanceChanged;
use App\Models\CarbonCreditTransaction;
use App\Models\LoyaltyTransaction;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Queues\QueueTestCase;

/**
 * ONE screen, THREE backend surfaces, and they must name the two ledgers the
 * same way.
 *
 * The Activity screen reads all three of these:
 *
 *   GET  book_a_ride/activity              -> item.scheme
 *   GET  book_a_ride/activity/unseen-count -> the keys under `unseen`
 *   socket event `balance.changed`         -> payload.scheme
 *
 * They were built as three separate pieces of work, and each picked its own word
 * for the loyalty ledger: the feed said "points", the badge said "points" and
 * "carbonCredits", the socket said "loyalty". Nothing on the server cared —
 * every surface was internally consistent and every test passed.
 *
 * The cost lands entirely on the client, and it does not land as an error. A
 * Dart `switch (scheme)` on a string that never matches takes the default arm:
 * no exception, no crash report, just a row that renders blank or a badge that
 * counts to zero. That is the worst class of contract bug — invisible on both
 * sides, and only a person looking at a phone can see it.
 *
 * So the vocabulary is asserted here, in one place, ACROSS the surfaces rather
 * than within each. A test that checks the feed says "loyalty" is worth little;
 * these compare the feed against the EVENT'S OWN CONSTANT, so the two cannot be
 * changed apart.
 *
 * `unit` is deliberately NOT part of this. The ledger is `loyalty`; the quantity
 * it is denominated in is `points`. Those are different questions and the feed
 * answers both — see PassengerActivityController::pointsItem.
 */
final class ActivityVocabularyTest extends QueueTestCase
{
    private const FEED = '/api/auth/book_a_ride/activity';

    private const COUNT = '/api/v1/auth/book_a_ride/activity/unseen-count';

    private function pointsRow(int $userId, int $saccoId, float $value = 12.5): void
    {
        LoyaltyTransaction::create([
            'user_id' => $userId,
            'sacco_id' => $saccoId,
            'value' => $value,
            'type' => LoyaltyTransactionType::Earned,
            'booking_id' => null,
        ]);
    }

    private function carbonRow(int $userId): void
    {
        CarbonCreditTransaction::create([
            'user_id' => $userId,
            'credits' => 1,
            'type' => CarbonCreditType::Earned,
            'spend_cents' => 100000,
            'description' => null,
        ]);
    }

    #[Test]
    public function the_feed_names_the_loyalty_ledger_exactly_as_the_socket_event_does(): void
    {
        $world = $this->makeWorld();
        $passenger = $this->makeUser();
        $this->pointsRow((int) $passenger->id, (int) $world['sacco']->id);

        Sanctum::actingAs($passenger);
        $row = $this->getJson(self::FEED)->assertOk()->json('activity.0');

        // Compared against the event's constant, not against a literal — that is
        // what makes the two surfaces impossible to change apart.
        $this->assertSame(
            PassengerBalanceChanged::SCHEME_LOYALTY,
            $row['scheme'],
            'the app switches on `scheme` from both the feed and the socket; one word, or the client silently drops rows'
        );
        $this->assertSame('points', $row['unit'], 'the ledger is loyalty, the quantity is points');

        // The id carries the scheme too, and it is a SECOND place the word
        // appears. Pinning only `scheme` left this behind on the first pass —
        // the feed answered 'loyalty' while its ids still read 'points:23'.
        $this->assertStringStartsWith(
            PassengerBalanceChanged::SCHEME_LOYALTY.':',
            $row['id'],
            'the id is scheme-qualified, so it has to use the same word the scheme does'
        );
    }

    #[Test]
    public function the_feed_names_the_carbon_ledger_exactly_as_the_socket_event_does(): void
    {
        $passenger = $this->makeUser();
        $this->carbonRow((int) $passenger->id);

        Sanctum::actingAs($passenger);
        $row = $this->getJson(self::FEED)->assertOk()->json('activity.0');

        $this->assertSame(PassengerBalanceChanged::SCHEME_CARBON, $row['scheme']);
        $this->assertSame('credits', $row['unit']);
        $this->assertStringStartsWith(PassengerBalanceChanged::SCHEME_CARBON.':', $row['id']);
    }

    #[Test]
    public function the_unseen_badge_uses_those_same_two_words_and_only_those(): void
    {
        // assertSame on the KEYS, not just their values: an extra key is how a
        // fourth spelling would creep back in without failing anything else.
        $passenger = $this->makeUser();

        Sanctum::actingAs($passenger);
        $unseen = $this->getJson(self::COUNT)->assertOk()->json('activity.unseen');

        $this->assertSame(
            [PassengerBalanceChanged::SCHEME_LOYALTY, PassengerBalanceChanged::SCHEME_CARBON, 'total'],
            array_keys($unseen),
            'the badge counts the same two ledgers the feed lists; it must call them the same thing'
        );
    }

    #[Test]
    public function scope_accepts_the_word_the_feed_itself_returns(): void
    {
        // The round trip. A client that reads `scheme` off a row and sends it
        // straight back as ?scope — the obvious thing to write for a filter chip
        // — has to work, and did not before the two words were the same.
        $world = $this->makeWorld();
        $passenger = $this->makeUser();
        $this->pointsRow((int) $passenger->id, (int) $world['sacco']->id, 5);
        $this->carbonRow((int) $passenger->id);

        Sanctum::actingAs($passenger);
        $scheme = $this->getJson(self::FEED.'?scope=all')->assertOk()->json('activity.0.scheme');

        $filtered = $this->getJson(self::FEED.'?scope='.$scheme)->assertOk()->json('activity');

        $this->assertNotEmpty($filtered);
        $this->assertSame([$scheme], array_values(array_unique(array_column($filtered, 'scheme'))));
    }

    #[Test]
    public function the_old_scope_spelling_still_answers_rather_than_400s(): void
    {
        // ?scope=points was this endpoint's word before it had a caller. It is
        // accepted on the way IN and never echoed OUT, so there is exactly one
        // spelling on the wire and no client is left with a 400 for having wired
        // against the earlier draft.
        $world = $this->makeWorld();
        $passenger = $this->makeUser();
        $this->pointsRow((int) $passenger->id, (int) $world['sacco']->id, 5);

        Sanctum::actingAs($passenger);
        $rows = $this->getJson(self::FEED.'?scope=points')->assertOk()->json('activity');

        $this->assertCount(1, $rows);
        $this->assertSame(PassengerBalanceChanged::SCHEME_LOYALTY, $rows[0]['scheme'], 'accepted in, never echoed out');
    }

    #[Test]
    public function no_surface_ever_puts_a_php_class_name_on_the_wire(): void
    {
        // The failure PlatformNotification::broadcastWith() exists to prevent,
        // checked here for the two surfaces that came after it. Laravel's
        // BroadcastNotificationCreated defaults `type` to get_class(), which is
        // how "App\Notifications\PlatformNotification" once reached a client
        // that was switching on it.
        $world = $this->makeWorld();
        $passenger = $this->makeUser();
        $this->pointsRow((int) $passenger->id, (int) $world['sacco']->id, 5);

        Sanctum::actingAs($passenger);

        foreach ([self::FEED, self::COUNT] as $url) {
            $this->assertStringNotContainsString(
                'App\\',
                (string) $this->getJson($url)->assertOk()->getContent(),
                $url.' leaked a PHP class name'
            );
        }
    }
}
