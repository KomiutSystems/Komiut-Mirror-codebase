<?php

declare(strict_types=1);

namespace App\Services\Sacco;

use App\Enums\SaccoClaimStatus;
use App\Models\Sacco;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * The SACCO directory: the list of names a driver picks from at onboarding.
 *
 * An entry is reference data — no user, no password, nothing to log into — until
 * the SACCO claims it (see SaccoClaimStatus). Drivers reach the directory before
 * they have an account, so both operations here are deliberately cheap and
 * assume nothing about the caller.
 *
 * Everything is brand-scoped by Sacco's global BrandScope; one brand never sees
 * another's directory.
 */
final class SaccoDirectory
{
    /** A type-ahead, not a listing: never return more than a screenful. */
    private const MAX_RESULTS = 20;

    /** Below this, every SACCO in the country matches — not worth a query. */
    private const MIN_QUERY_LENGTH = 2;

    private const SOURCE_DRIVER_SUBMITTED = 'driver_submitted';

    /**
     * Name matches for the given fragment, ordered by name and capped.
     *
     * @return Collection<int, Sacco>
     */
    public function search(string $query, int $limit = self::MAX_RESULTS): Collection
    {
        $term = trim($query);

        if (mb_strlen($term) < self::MIN_QUERY_LENGTH) {
            return new Collection();
        }

        // LOWER() both sides rather than ILIKE: Postgres LIKE is case-sensitive,
        // sqlite's is not, and this behaves identically on both.
        return Sacco::query()
            ->whereRaw("LOWER(name) LIKE ? ESCAPE '\\'", ['%' . $this->escapeLike($term) . '%'])
            ->orderBy('name')
            ->limit(max(1, min($limit, self::MAX_RESULTS)))
            ->get(['id', 'name']);
    }

    /**
     * The SACCO a driver named, creating a directory entry if we have never
     * heard of it.
     *
     * A submitted entry is searchable straight away — the next driver at the
     * same stage should find it rather than submit a third spelling — but sits
     * in PendingReview because it exists on one person's word alone.
     */
    public function resolveOrSubmit(string $name): Sacco
    {
        $name = trim($name);

        $existing = $this->findByName($name);
        if ($existing !== null) {
            return $existing;
        }

        try {
            return Sacco::create([
                'name' => $name,
                'status' => 1,
                'claim_status' => SaccoClaimStatus::PendingReview,
                'source' => self::SOURCE_DRIVER_SUBMITTED,
            ]);
        } catch (UniqueConstraintViolationException $e) {
            // Two drivers submitted the same new SACCO at once; `saccos.name` is
            // unique, so whoever lost the race reads back the winner's row.
            return $this->findByName($name) ?? throw $e;
        }
    }

    /**
     * The directory entry for this name that nobody has claimed yet, if any.
     *
     * This is what makes self-registration work after drivers have already been
     * onboarded: the name is taken, but only by reference data. Returns null
     * when the name is free, or when a real SACCO already owns it.
     */
    public function unclaimedByName(string $name): ?Sacco
    {
        $existing = $this->findByName(trim($name));

        return $existing?->claim_status === SaccoClaimStatus::Claimed ? null : $existing;
    }

    /** Whether any SACCO — directory entry or real tenant — holds this name. */
    public function isNameTaken(string $name): bool
    {
        return $this->findByName(trim($name)) !== null;
    }

    /**
     * Promote a directory entry to a real tenant, keeping its id — so every
     * driver already attached to it comes along with the claim.
     */
    public function markClaimed(Sacco $sacco, string $email, string $phone): Sacco
    {
        $sacco->forceFill([
            'email' => $email,
            'phone' => $phone,
            'status' => 1,
            'claim_status' => SaccoClaimStatus::Claimed,
            'verified_at' => now(),
        ])->save();

        return $sacco;
    }

    /** Exact match ignoring case, so "Nicco" and "nicco sacco " don't fork. */
    private function findByName(string $name): ?Sacco
    {
        return Sacco::query()
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->first();
    }

    /** Neutralise wildcards a passenger typed so `%` searches for a `%`. */
    private function escapeLike(string $term): string
    {
        return addcslashes(mb_strtolower($term), '%_\\');
    }
}
