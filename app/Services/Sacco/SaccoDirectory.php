<?php

declare(strict_types=1);

namespace App\Services\Sacco;

use App\Enums\SaccoClaimStatus;
use App\Models\Sacco;
use App\Models\User;
use App\Models\Vehicle;
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
            // Deactivating a SACCO must remove it from the picker a driver sees.
            // Without this the only way to retire a bad entry is to delete it,
            // which is irreversible and takes its members with it.
            ->where('status', 1)
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
     * The SACCO an UNAUTHENTICATED caller may claim by name, or null.
     *
     * "Not already claimed" is not sufficient, and treating it as sufficient was
     * a tenant-takeover hole: registerSacco is a public endpoint, claiming keeps
     * the directory entry's id, and the claimer is made a SACCO Admin on it. So
     * anyone who could read a SACCO's name — from the public type-ahead on this
     * same service — could post it here and take over the real row: its vehicles,
     * its takings, its crew, its M-Pesa settings.
     *
     * Measured on production before this gate: 48 SACCOs were claimable and 45 of
     * them had substance, including one with 180 vehicles collecting KES 124,000
     * on the day this was written. Three were genuinely empty.
     *
     * A row with NO users and NO vehicles is an empty directory stub — there is
     * nothing behind it to steal, and claiming it is exactly what this flow is
     * for. A row with either is somebody's business, and acquiring it has to go
     * through a human. That is the whole test: substance, not provenance. Source
     * is deliberately not part of it — a legacy directory entry that never had
     * anything attached is as safe to claim as a driver-submitted one, and gating
     * on source would block the legitimate case while adding nothing.
     */
    public function claimableByName(string $name): ?Sacco
    {
        $existing = $this->findByName(trim($name));

        if ($existing === null || $existing->claim_status === SaccoClaimStatus::Claimed) {
            return null;
        }

        return $this->hasSubstance($existing) ? null : $existing;
    }

    /**
     * Whether this name exists, is unclaimed, and has something attached — the
     * case claimableByName() refuses.
     *
     * It exists so the caller can be told the truth. "This SACCO is already
     * registered, ask its admin to add you" is the right message for a CLAIMED
     * name and a wrong one here: nobody has registered it, so there is no admin
     * to ask, and a real operator reading that would reasonably conclude the
     * platform had lost their account.
     */
    public function requiresVerifiedClaim(string $name): bool
    {
        $existing = $this->findByName(trim($name));

        return $existing !== null
            && $existing->claim_status !== SaccoClaimStatus::Claimed
            && $this->hasSubstance($existing);
    }

    /**
     * Is there a business behind this row — anyone signed up to it, any bus on
     * it — or is it just a name?
     *
     * withoutGlobalScopes: this runs unauthenticated so no scope is active
     * anyway. Being explicit stops a future scope change silently making a
     * populated SACCO look empty, which would re-open the takeover hole.
     */
    private function hasSubstance(Sacco $sacco): bool
    {
        return User::withoutGlobalScopes()->where('sacco_id', $sacco->id)->exists()
            || Vehicle::withoutGlobalScopes()->where('sacco_id', $sacco->id)->exists();
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
