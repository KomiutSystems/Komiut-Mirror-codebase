<?php

declare(strict_types=1);

namespace App\Services\Team\AutoCards;

use App\Models\Team\TeamCard;
use App\Models\Team\TeamCardComment;
use App\Services\Team\TeamBoard;
use Illuminate\Support\Collection;

/**
 * What every automatic-card source shares: raising and closing through
 * TeamBoard's openAuto / closeAuto (which hold the one-open-card rule and the
 * Waiting rule), plus the two judgements those primitives leave to us --
 *
 *  - the hourly sweep must not overrule the team. A card a PERSON moved to
 *    Done while the problem was still there is a decision ("we told the
 *    SACCO, nothing more to do"), and reopening it every hour would turn the
 *    board into noise. The sweep only fills in cards that were never opened;
 *    the next real event (a fresh refusal, a new flag) still opens a new one.
 *  - closeAuto comments on a Waiting card every time it is asked. Asked once
 *    per event that is right; asked by the sweep every hour it is spam, so a
 *    Waiting card that already says "resolved on the platform" is left alone.
 */
abstract class AutoCardSource
{
    /** Opened by one sweep at most, per source: a backlog arrives over a few hours, not as one wall. */
    public const SWEEP_OPEN_LIMIT = 200;

    /** What TeamBoard::closeAuto writes; how we recognise the platform's own words. */
    private const CLOSED_ITSELF = 'Closed itself:';

    private const RESOLVED_WHILE_WAITING = 'Resolved on the platform:';

    public function __construct(protected readonly TeamBoard $board) {}

    /** The card source this class raises (TeamCard::AUTO_SOURCES). */
    abstract public function source(): string;

    /**
     * The hourly reconciliation: open what is missing, close what has
     * resolved. Returns counts for the command's output.
     *
     * @return array{opened:int, closed:int}
     */
    abstract public function sweep(): array;

    /** This source's open card on one thing, if any. */
    protected function openCard(string $linkType, int $linkId): ?TeamCard
    {
        return TeamCard::query()
            ->where('source', $this->source())->where('link_type', $linkType)->where('link_id', $linkId)
            ->where('status', '!=', 'done')
            ->first();
    }

    /**
     * Every open card of this source, keyed by link id. The board's open
     * cards are a working set (tens, a few hundred) -- never the history.
     *
     * @return Collection<int, TeamCard>
     */
    protected function openCards(string $linkType): Collection
    {
        return TeamCard::query()
            ->where('source', $this->source())->where('link_type', $linkType)
            ->where('status', '!=', 'done')
            ->get(['id', 'link_id', 'status'])
            ->keyBy('link_id');
    }

    /**
     * Raise (or find) the card for this problem.
     *
     * $sweep: called by the hourly reconciliation, which respects a person's
     * Done (see the class note) and never comments. $again: a line for an
     * already-open card when an EVENT says the problem happened again or
     * changed -- the card then tells the story instead of silently swallowing
     * it. Returns null when the sweep left it to the team.
     */
    protected function raise(string $linkType, int $linkId, string $title, ?string $description, string $priority, bool $sweep = false, ?string $again = null): ?TeamCard
    {
        if ($sweep && $this->openCard($linkType, $linkId) === null && $this->closedByPerson($linkType, $linkId)) {
            return null;
        }

        $card = $this->board->openAuto($this->source(), $linkType, $linkId, $title, $description, $priority);

        if (! $sweep && ! $card->wasRecentlyCreated && $again !== null) {
            $this->board->comment($card, null, $again);
        }

        return $card;
    }

    /**
     * Close this source's card on one thing because the problem went away.
     * Returns whether anything was written.
     */
    protected function resolve(string $linkType, int $linkId, string $why): bool
    {
        $card = $this->openCard($linkType, $linkId);
        if ($card === null) {
            return false;
        }

        if ($card->status === 'waiting' && $this->alreadyToldWaiting($card)) {
            return false;
        }

        $this->board->closeAuto($this->source(), $linkType, $linkId, $why);

        return true;
    }

    /**
     * Did a person, not the platform, close the latest card on this thing?
     * The platform's own close always leaves "Closed itself:"; a card moved to
     * Done by hand does not.
     */
    protected function closedByPerson(string $linkType, int $linkId): bool
    {
        $latest = TeamCard::query()
            ->where('source', $this->source())->where('link_type', $linkType)->where('link_id', $linkId)
            ->orderByDesc('id')
            ->first(['id', 'status']);

        if ($latest === null || $latest->status !== 'done') {
            return false;
        }

        return ! TeamCardComment::query()
            ->where('card_id', $latest->id)->whereNull('author_id')
            ->where('body', 'like', self::CLOSED_ITSELF.'%')
            ->exists();
    }

    /** The card's newest comment is already the platform saying "resolved". */
    private function alreadyToldWaiting(TeamCard $card): bool
    {
        $last = TeamCardComment::query()->where('card_id', $card->id)->orderByDesc('id')->first(['author_id', 'body']);

        return $last !== null && $last->author_id === null && str_starts_with((string) $last->body, self::RESOLVED_WHILE_WAITING);
    }

    /** "1 bus", "3 buses". */
    protected static function count(int $n, string $one, string $many): string
    {
        return $n.' '.($n === 1 ? $one : $many);
    }
}
