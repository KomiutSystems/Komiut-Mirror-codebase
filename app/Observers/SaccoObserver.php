<?php

declare(strict_types=1);

namespace App\Observers;

use App\Enums\SaccoClaimStatus;
use App\Models\Sacco;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Platform\AuditLogger;
use App\Services\Platform\PlatformEvent;
use App\Services\Platform\PlatformNotifier;
use App\Services\Platform\Thresholds;
use Illuminate\Support\Facades\Auth;

/**
 * Turns SACCO lifecycle transitions into super-admin console events. Observing the
 * model (not the controllers) means every path that touches a SACCO — self
 * registration, an admin claim, a directory import — is caught in one place.
 *
 * PII rule: `data` payloads carry ids/counts only. A SACCO `name` is a business
 * name (not a person), so it is allowed in title/summary/data.
 */
class SaccoObserver
{
    public function __construct(private readonly PlatformNotifier $notifier) {}

    public function created(Sacco $sacco): void
    {
        $this->emitPendingReview($sacco);
        $this->emitDuplicateSuspected($sacco);
    }

    public function updated(Sacco $sacco): void
    {
        // AUDIT-FIRST for the two sensitive transitions.
        if ($sacco->wasChanged('claim_status') && $sacco->claim_status === SaccoClaimStatus::Claimed) {
            $this->emitClaimed($sacco);
        }

        if ($sacco->wasChanged('status')) {
            $this->emitStatusChanged($sacco);
        }
    }

    /**
     * Event 1 — sacco.pending_review.created (review / normal, no throttle).
     * A brand-new SACCO that is a directory entry or driver-submitted pending row
     * belongs in the review queue; a row created already-claimed does not.
     */
    private function emitPendingReview(Sacco $sacco): void
    {
        // A row created without an explicit claim_status takes the schema default
        // ('directory'), which Eloquent hasn't hydrated onto the fresh instance yet.
        $status = $sacco->claim_status ?? SaccoClaimStatus::Directory;
        if (! in_array($status, [SaccoClaimStatus::Directory, SaccoClaimStatus::PendingReview], true)) {
            return;
        }

        $this->notifier->dispatch(new PlatformEvent(
            event: 'sacco.pending_review.created',
            severity: 'normal',
            class: 'review',
            title: 'SACCO pending review',
            summary: $this->cap("New SACCO \"{$sacco->name}\" added ({$status->value}) — awaiting review."),
            brand: $sacco->brand,
            actor: $this->actor(),
            subject: ['type' => 'sacco', 'id' => $sacco->id],
            data: [
                'saccoId' => $sacco->id,
                'name' => $sacco->name,
                // onboardedDriverId: the SACCO row carries no submitting-driver id,
                // so it is omitted rather than fabricated (the field is optional).
            ],
        ));
    }

    /**
     * Event 2 — sacco.duplicate.suspected (review / high, 24h per pair).
     * Fuzzy-matches the new name against existing SACCOs of the same brand with a
     * normalised Levenshtein ratio. Bulk register imports (sasra/ntsa) are skipped
     * so a large directory load does not run a quadratic full-table scan.
     */
    private function emitDuplicateSuspected(Sacco $sacco): void
    {
        if (in_array((string) $sacco->source, ['sasra', 'ntsa'], true)) {
            return;
        }

        $threshold = (float) Thresholds::get($sacco->brand, 'sacco_duplicate_score');
        $needle = $this->normalise($sacco->name);
        if ($needle === '') {
            return;
        }

        $firstToken = explode(' ', $needle)[0];

        // Cross-scope + explicit brand filter so console/HTTP/test behave identically.
        // Pre-filter on the first token keeps the Levenshtein pass bounded.
        $candidates = Sacco::withoutGlobalScopes()
            ->where('brand', $sacco->brand)
            ->where('id', '!=', $sacco->id)
            ->whereRaw('LOWER(name) LIKE ?', [$firstToken.'%'])
            ->limit(200)
            ->get(['id', 'name']);

        $matches = [];
        foreach ($candidates as $candidate) {
            $score = $this->similarity($needle, $this->normalise((string) $candidate->name));
            if ($score >= $threshold) {
                $matches[] = ['id' => $candidate->id, 'name' => $candidate->name, 'score' => round($score, 2)];
            }
        }

        if ($matches === []) {
            return;
        }

        usort($matches, static fn ($a, $b) => $b['score'] <=> $a['score']);
        $top = $matches[0];
        $pair = [$sacco->id, (int) $top['id']];
        sort($pair);

        $topScore = (int) round(((float) $top['score']) * 100);

        $this->notifier->dispatch(new PlatformEvent(
            event: 'sacco.duplicate.suspected',
            severity: 'high',
            class: 'review',
            title: 'Possible duplicate SACCO',
            summary: $this->cap("\"{$sacco->name}\" resembles ".count($matches)." existing SACCO(s) (top {$topScore}%)."),
            brand: $sacco->brand,
            actor: $this->actor(),
            subject: ['type' => 'sacco', 'id' => $sacco->id],
            data: [
                'saccoId' => $sacco->id,
                'name' => $sacco->name,
                'matches' => $matches,
            ],
            dedupeKey: 'sacco.duplicate:'.$pair[0].':'.$pair[1],
            windowMinutes: 24 * 60,
        ));
    }

    /**
     * Event 3 — sacco.claimed (alert / high, no throttle, AUDIT-FIRST).
     * A directory/pending SACCO becoming an account with a login. Records the
     * inherited driver/vehicle footprint the new owner now controls.
     */
    private function emitClaimed(Sacco $sacco): void
    {
        $previous = $sacco->getOriginal('claim_status'); // cast → enum instance
        $previousValue = $previous instanceof SaccoClaimStatus ? $previous->value : (string) $sacco->getRawOriginal('claim_status');

        $driverCount = User::withoutGlobalScopes()->where('sacco_id', $sacco->id)->count();
        $vehicleCount = Vehicle::withoutGlobalScopes()->where('sacco_id', $sacco->id)->count();

        $data = [
            'saccoId' => $sacco->id,
            'name' => $sacco->name,
            'previousClaimStatus' => $previousValue,
            'inheritedDriverCount' => $driverCount,
            'inheritedVehicleCount' => $vehicleCount,
        ];

        $subject = ['type' => 'sacco', 'id' => (string) $sacco->id];
        $audit = AuditLogger::record('sacco.claimed', $data, $this->actor(), $subject, $sacco->brand);

        $this->notifier->dispatch(new PlatformEvent(
            event: 'sacco.claimed',
            severity: 'high',
            class: 'alert',
            title: 'SACCO claimed',
            summary: $this->cap("\"{$sacco->name}\" claimed (was {$previousValue}); inherited {$driverCount} driver(s), {$vehicleCount} vehicle(s)."),
            brand: $sacco->brand,
            actor: $this->actor(),
            subject: ['type' => 'sacco', 'id' => $sacco->id],
            data: $data,
            auditId: $audit->id,
        ));
    }

    /**
     * Event 5 — sacco.status.changed (alert / high, no throttle, AUDIT-FIRST).
     * The active/inactive flag flipping (0 = pending/inactive, 1 = active).
     */
    private function emitStatusChanged(Sacco $sacco): void
    {
        $from = (int) $sacco->getOriginal('status');
        $to = (int) $sacco->status;

        $data = [
            'saccoId' => $sacco->id,
            'name' => $sacco->name,
            'from' => $from,
            'to' => $to,
        ];

        $subject = ['type' => 'sacco', 'id' => (string) $sacco->id];
        $audit = AuditLogger::record('sacco.status.changed', $data, $this->actor(), $subject, $sacco->brand);

        $this->notifier->dispatch(new PlatformEvent(
            event: 'sacco.status.changed',
            severity: 'high',
            class: 'alert',
            title: 'SACCO status changed',
            summary: $this->cap("\"{$sacco->name}\" status {$from} → {$to}."),
            brand: $sacco->brand,
            actor: $this->actor(),
            subject: ['type' => 'sacco', 'id' => $sacco->id],
            data: $data,
            auditId: $audit->id,
        ));
    }

    /** Normalised Levenshtein ratio in [0,1]. levenshtein() is byte-based (255 cap) — fine for names. */
    private function similarity(string $a, string $b): float
    {
        $max = max(strlen($a), strlen($b));
        if ($max === 0) {
            return 0.0;
        }

        return 1 - (levenshtein($a, $b) / $max);
    }

    /** Lowercase, strip punctuation, collapse whitespace. "sacco" is kept — it is part of most names. */
    private function normalise(string $name): string
    {
        $name = strtolower($name);
        $name = preg_replace('/[^a-z0-9 ]+/', ' ', $name) ?? '';

        return trim(preg_replace('/\s+/', ' ', $name) ?? '');
    }

    /** Summaries are shown verbatim and emailed — hard cap at 140. */
    private function cap(string $summary): string
    {
        return mb_substr($summary, 0, 140);
    }

    /** @return array{type:string,id:?string,label:?string} */
    private function actor(): array
    {
        $user = Auth::user();
        if (! $user instanceof User) {
            return ['type' => 'system', 'id' => null, 'label' => null];
        }

        return [
            'type' => 'user',
            'id' => (string) $user->getAuthIdentifier(),
            'label' => trim(($user->firstname ?? '').' '.($user->lastname ?? '')) ?: null,
        ];
    }
}
