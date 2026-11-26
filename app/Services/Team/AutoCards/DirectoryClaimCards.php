<?php

declare(strict_types=1);

namespace App\Services\Team\AutoCards;

use App\Enums\SaccoClaimStatus;
use App\Models\Sacco;

/**
 * A card per SACCO waiting in the directory review queue.
 *
 * A SACCO is waiting when it is pending_review (a driver typed a name we did
 * not have -- SaccoDirectory::resolveOrSubmit) and still active. Plain
 * 'directory' rows are imported registers (SASRA/NTSA), reference data
 * nobody has to review, so they raise nothing.
 *
 * How review ends, and so what closes the card (DirectoryController):
 *   approve -- claim_status becomes claimed;
 *   reject  -- the row is deactivated (status 0), claim_status untouched;
 *   merge   -- its drivers and buses move to the other SACCO and the row is
 *              deactivated the same way;
 * and a SACCO deleted outright closes it too. A deactivated row also leaves
 * the console's own queue (it lists status = 1 only), so the card and the
 * queue agree.
 */
class DirectoryClaimCards extends AutoCardSource
{
    private const COLUMNS = ['id', 'name', 'status', 'claim_status', 'source', 'created_at'];

    public function source(): string
    {
        return 'directory_claim';
    }

    public function sync(int $saccoId, bool $event = true): void
    {
        $this->apply($saccoId, Sacco::withoutGlobalScopes()->find($saccoId, self::COLUMNS), sweep: ! $event);
    }

    public function sweep(): array
    {
        $opened = 0;
        $closed = 0;
        $open = $this->openCards('sacco');

        // claim_status is indexed; the review queue is short by nature.
        $pending = Sacco::withoutGlobalScopes()
            ->where('claim_status', SaccoClaimStatus::PendingReview->value)
            ->where('status', 1)
            ->orderBy('id')
            ->limit(self::SWEEP_OPEN_LIMIT * 5)
            ->get(self::COLUMNS);

        foreach ($pending as $sacco) {
            if ($opened >= self::SWEEP_OPEN_LIMIT) {
                break;
            }
            if (! $open->has((int) $sacco->id) && $this->apply((int) $sacco->id, $sacco, sweep: true)) {
                $opened++;
            }
        }

        $stillPending = $pending->keyBy('id');
        $toCheck = $open->keys()->reject(fn ($id) => $stillPending->has($id))->values();
        foreach ($toCheck->chunk(500) as $ids) {
            $saccos = Sacco::withoutGlobalScopes()->whereIn('id', $ids->all())->get(self::COLUMNS)->keyBy('id');
            foreach ($ids as $id) {
                if ($this->apply((int) $id, $saccos->get($id), sweep: true)) {
                    $closed++;
                }
            }
        }

        return ['opened' => $opened, 'closed' => $closed];
    }

    /** Returns whether a card was opened or closed. */
    private function apply(int $saccoId, ?Sacco $sacco, bool $sweep): bool
    {
        if ($sacco === null) {
            return $this->resolve('sacco', $saccoId, 'the SACCO was deleted.');
        }

        if ($sacco->claim_status === SaccoClaimStatus::PendingReview && (int) $sacco->status === 1) {
            $card = $this->raise(
                'sacco',
                $saccoId,
                $sacco->name.' · new SACCO to review',
                implode("\n", [
                    'Added '.($sacco->source === 'driver_submitted' ? 'by a driver during onboarding' : 'to the directory').($sacco->created_at ? ' on '.$sacco->created_at->format('j M Y') : '').', on one person\'s word.',
                    'Approve it, reject it, or merge it into the SACCO it really is, under Directory. This card closes itself when that is done.',
                ]),
                'normal',
                $sweep,
            );

            return $card !== null && $card->wasRecentlyCreated;
        }

        $why = match (true) {
            $sacco->claim_status === SaccoClaimStatus::Claimed => 'approved -- the SACCO is claimed now.',
            (int) $sacco->status !== 1 => 'rejected or merged into another SACCO (the entry is deactivated).',
            default => 'it is no longer waiting for review (now '.($sacco->claim_status?->value ?? 'unknown').').',
        };

        return $this->resolve('sacco', $saccoId, $why);
    }
}
