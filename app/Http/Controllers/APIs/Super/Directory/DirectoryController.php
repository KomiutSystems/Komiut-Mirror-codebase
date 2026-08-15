<?php

declare(strict_types=1);

namespace App\Http\Controllers\APIs\Super\Directory;

use App\Enums\SaccoClaimStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\Super\SlimPage;
use App\Models\Sacco;
use App\Models\SaccoUser;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Platform\AuditLogger;
use App\Services\Platform\PlatformEvent;
use App\Services\Platform\PlatformNotifier;
use App\Services\Platform\Thresholds;
use App\Services\Sql\LikeSql;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * The directory claim-review workflow for the super-admin console: the queue of
 * un-claimed/pending SACCO rows, duplicate-suspect hints, and the three admin
 * actions (approve / reject / merge) that resolve a row.
 *
 * These are admin actions layered ON TOP of App\Observers\SaccoObserver, not a
 * replacement for it: flipping `claim_status` or `status` via Eloquent save()
 * lets the observer's own sacco.claimed / sacco.status.changed events fire
 * naturally, so this controller only audits/notifies the parts the observer
 * doesn't already cover (the admin decision itself, and the merge, which has no
 * observer trigger of its own). Gated by `View Platform Notifications` at the
 * route.
 */
class DirectoryController extends Controller
{
    /** Only a claimed SACCO has a live account behind it — see SaccoClaimStatus. */
    private const SOURCE_DIRECTORY_IMPORT = ['sasra', 'ntsa'];

    /**
     * Filters: q (name), brand, status (claim_status: directory|pending_review|
     * claimed). Scoped to `status = 1` (active) like SaccoDirectory::search — a
     * deactivated row (reject/merge) drops out of the queue, which is also what
     * makes those two actions observable here.
     */
    public function index(Request $request): SlimPage
    {
        $perPage = min((int) $request->input('per_page', 25), 100);

        $query = Sacco::query()
            ->where('status', 1)
            ->when($request->filled('status'), fn ($q) => $q->where('claim_status', $request->input('status')))
            ->when($request->filled('brand'), fn ($q) => $q->where('brand', $request->input('brand')))
            ->when($request->filled('q'), fn ($q) => $q->where('name', LikeSql::op(), '%'.$request->input('q').'%'))
            ->orderByDesc('created_at');

        $page = $query->paginate($perPage);

        return SlimPage::of($page, fn (Sacco $sacco): array => $this->row($sacco));
    }

    /**
     * POST directory/{id}/merge {into_sacco_id}. Reassigns this SACCO's users
     * (drivers + admins), SaccoUser memberships, and vehicles onto the target,
     * then deactivates this row (status=0) so it drops out of the directory. The
     * losing row is kept — never hard-deleted — for the audit trail.
     */
    public function merge(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'into_sacco_id' => 'required|integer|exists:saccos,id',
        ]);

        $sacco = Sacco::findOrFail($id);
        $target = Sacco::findOrFail((int) $validated['into_sacco_id']);

        // A genuine conflict: both sides already have a live account behind them,
        // so there is no "loser" to fold — the caller must pick one to unclaim first.
        if ($sacco->claim_status === SaccoClaimStatus::Claimed && $target->claim_status === SaccoClaimStatus::Claimed) {
            return response()->json([
                'success' => false,
                'message' => 'Both SACCOs are already claimed; merge would fold one live tenant into another.',
            ], 409);
        }

        $reassignedDrivers = 0;
        $reassignedVehicles = 0;

        DB::transaction(function () use ($sacco, $target, &$reassignedDrivers, &$reassignedVehicles): void {
            $reassignedDrivers = User::where('sacco_id', $sacco->id)->count();
            User::where('sacco_id', $sacco->id)->update(['sacco_id' => $target->id]);
            SaccoUser::where('sacco_id', $sacco->id)->update(['sacco_id' => $target->id]);

            $reassignedVehicles = Vehicle::where('sacco_id', $sacco->id)->count();
            Vehicle::where('sacco_id', $sacco->id)->update(['sacco_id' => $target->id]);

            // No longer active in the directory — status flip fires the observer's
            // own sacco.status.changed event, same as reject().
            $sacco->forceFill(['status' => 0])->save();
        });

        // AUDIT-FIRST: the merge has no observer trigger of its own.
        $audit = AuditLogger::record(
            'sacco.directory.merged',
            [
                'fromSaccoId' => $sacco->id,
                'intoSaccoId' => $target->id,
                'reassignedDrivers' => $reassignedDrivers,
                'reassignedVehicles' => $reassignedVehicles,
            ],
            null,
            ['type' => 'sacco', 'id' => (string) $sacco->id],
            $sacco->brand,
        );

        app(PlatformNotifier::class)->dispatch(new PlatformEvent(
            event: 'sacco.directory.merged',
            severity: 'high',
            class: 'alert',
            title: 'SACCO directory entries merged',
            summary: mb_substr(
                "\"{$sacco->name}\" merged into \"{$target->name}\" ({$reassignedDrivers} driver(s), {$reassignedVehicles} vehicle(s)).",
                0,
                140,
            ),
            brand: $sacco->brand,
            actor: $this->actor(),
            subject: ['type' => 'sacco', 'id' => $sacco->id],
            data: [
                'fromSaccoId' => $sacco->id,
                'intoSaccoId' => $target->id,
                'reassignedDrivers' => $reassignedDrivers,
                'reassignedVehicles' => $reassignedVehicles,
            ],
            auditId: $audit->id,
            // never throttled — windowMinutes stays 0 (the PlatformEvent default)
        ));

        return response()->json(['success' => true, 'sacco' => $this->row($sacco->fresh())]);
    }

    /**
     * POST directory/{id}/approve. Flips claim_status pending_review -> claimed;
     * saving via Eloquent lets SaccoObserver::emitClaimed fire the sacco.claimed
     * event (and its own audit row) naturally. This only audits the admin
     * decision itself.
     */
    public function approve(int $id): JsonResponse
    {
        $sacco = Sacco::findOrFail($id);

        $sacco->forceFill([
            'claim_status' => SaccoClaimStatus::Claimed,
            'verified_at' => now(),
        ])->save();

        AuditLogger::record(
            'sacco.directory.approved',
            ['saccoId' => $sacco->id, 'name' => $sacco->name],
            null,
            ['type' => 'sacco', 'id' => (string) $sacco->id],
            $sacco->brand,
        );

        return response()->json(['success' => true, 'sacco' => $this->row($sacco->fresh())]);
    }

    /**
     * POST directory/{id}/reject {reason}. Deactivates the row (status=0, which
     * also fires the observer's sacco.status.changed) and emits a dedicated
     * review-decision event, since rejection with a reason has no existing
     * trigger of its own.
     */
    public function reject(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate(['reason' => 'required|string']);

        $sacco = Sacco::findOrFail($id);
        $sacco->forceFill(['status' => 0])->save();

        // AUDIT-FIRST.
        $audit = AuditLogger::record(
            'sacco.directory.rejected',
            ['saccoId' => $sacco->id, 'name' => $sacco->name, 'reason' => $validated['reason']],
            null,
            ['type' => 'sacco', 'id' => (string) $sacco->id],
            $sacco->brand,
        );

        app(PlatformNotifier::class)->dispatch(new PlatformEvent(
            event: 'sacco.directory.rejected',
            severity: 'normal',
            class: 'alert',
            title: 'SACCO directory entry rejected',
            summary: mb_substr("\"{$sacco->name}\" rejected: {$validated['reason']}", 0, 140),
            brand: $sacco->brand,
            actor: $this->actor(),
            subject: ['type' => 'sacco', 'id' => $sacco->id],
            data: ['saccoId' => $sacco->id, 'name' => $sacco->name, 'reason' => $validated['reason']],
            auditId: $audit->id,
        ));

        return response()->json(['success' => true, 'sacco' => $this->row($sacco->fresh())]);
    }

    /**
     * The directory row shape — the same base fields as SaccosController's list
     * row, plus the directory-specific fields.
     *
     * @return array<string,mixed>
     */
    private function row(Sacco $sacco): array
    {
        return [
            'id' => $sacco->id,
            'name' => $sacco->name,
            'brand' => $sacco->brand,
            'claim_status' => $sacco->claim_status?->value,
            'created_at' => optional($sacco->created_at)->toIso8601String(),
            'attached_drivers' => User::where('sacco_id', $sacco->id)->count(),
            'attached_vehicles' => Vehicle::where('sacco_id', $sacco->id)->count(),
            'suggested_matches' => $this->suggestedMatches($sacco),
            'created_via' => $this->createdVia($sacco->source),
        ];
    }

    /**
     * "directory" for a bulk SASRA/NTSA import, "driver_onboarding" for
     * everything else (driver_submitted, manual, self_registered, or no source
     * at all) — see database/migrations/2026_07_27_120000_add_directory_fields_
     * to_saccos_table.php for the full value set.
     */
    private function createdVia(?string $source): string
    {
        return in_array($source, self::SOURCE_DIRECTORY_IMPORT, true) ? 'directory' : 'driver_onboarding';
    }

    /**
     * The same normalised-Levenshtein duplicate check as
     * SaccoObserver::emitDuplicateSuspected (same threshold key, same
     * prefix-token pre-filter to keep it bounded), reimplemented inline per the
     * file-ownership split — this controller may not touch the observer or add a
     * shared service class outside its own files.
     *
     * @return array<int,array{id:int,name:string,score:float}>
     */
    private function suggestedMatches(Sacco $sacco): array
    {
        $threshold = (float) Thresholds::get($sacco->brand, 'sacco_duplicate_score');
        $needle = $this->normalise($sacco->name);
        if ($needle === '') {
            return [];
        }

        $firstToken = explode(' ', $needle)[0];

        $candidates = Sacco::query()
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

        usort($matches, static fn ($a, $b) => $b['score'] <=> $a['score']);

        return array_slice($matches, 0, 5);
    }

    /** Normalised Levenshtein ratio in [0,1]. Mirrors SaccoObserver::similarity(). */
    private function similarity(string $a, string $b): float
    {
        $max = max(strlen($a), strlen($b));
        if ($max === 0) {
            return 0.0;
        }

        return 1 - (levenshtein($a, $b) / $max);
    }

    /** Lowercase, strip punctuation, collapse whitespace. Mirrors SaccoObserver::normalise(). */
    private function normalise(string $name): string
    {
        $name = strtolower($name);
        $name = preg_replace('/[^a-z0-9 ]+/', ' ', $name) ?? '';

        return trim(preg_replace('/\s+/', ' ', $name) ?? '');
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
