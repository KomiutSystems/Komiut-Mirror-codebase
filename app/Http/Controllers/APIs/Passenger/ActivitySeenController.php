<?php

declare(strict_types=1);

namespace App\Http\Controllers\APIs\Passenger;

use App\Http\Controllers\Controller;
use App\Models\CarbonCreditTransaction;
use App\Models\LoyaltyTransaction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * @group Activity
 *
 * The unread indicator on the passenger Activity screen.
 *
 * SERVER-SIDE, KEYED TO THE USER. A device-local "last seen" flag fails three
 * ways that matter here: it is lost on reinstall, it disagrees between a
 * passenger's two devices, and — the one that decides it — matatu crews and
 * families SHARE HANDSETS, so a per-device flag shows one person's unread state
 * to the next person to pick the phone up. The marker lives on the user row, so
 * it survives a reinstall, agrees everywhere, and stays separate per person on
 * one phone.
 *
 * IT MUST NOT LIE. A badge that shows "new" immediately after somebody has
 * looked is the failure that teaches people to ignore the badge for good, so
 * the count is written to be wrong in the safe direction only:
 *
 *  - `seen` returns the counts as well as the timestamp, computed through the
 *    same method the count endpoint uses, so the app can clear the badge with no
 *    second call and the two endpoints cannot drift apart.
 *  - The comparison is STRICT `>`. The marker and both ledgers' `created_at` are
 *    Postgres `timestamp(0)`, and Laravel binds dates as 'Y-m-d H:i:s', so both
 *    sides are truncated to the second: an entry written in the same second as
 *    the mark compares EQUAL and is therefore not unseen. Truncation is
 *    monotonic, so nothing created BEFORE the mark can ever land on a later
 *    second than it — no entry the passenger has already seen can come back.
 *
 * It also has to work with no socket at all, which is the normal condition on a
 * moving matatu: this is a plain authenticated GET a cold start can make, not a
 * live subscription.
 */
class ActivitySeenController extends Controller
{
    public function __construct()
    {
        // REQUIRED, not decorative. These routes sit in the `user_status_api`
        // group, whose middleware runs BEFORE route middleware; a controller
        // that lands there without this is unauthenticated in fact while looking
        // authenticated, and CheckAPIUserStatus reads the status of nobody. That
        // is what signed passengers out of production once already.
        $this->middleware('auth:sanctum');
    }

    /**
     * Mark my activity as seen
     *
     * Stamps now() as the moment this passenger last looked at their Activity
     * screen, and answers with the new marker plus the counts that go with it —
     * so the app can clear the badge without a refetch and without guessing.
     *
     * @authenticated
     *
     * @response 200 {"activity": {"seenAt": "2026-09-08T10:00:00+00:00", "unseen": {"loyalty": 0, "carbon": 0, "total": 0}}}
     */
    public function seen(Request $request): JsonResponse
    {
        $user = $request->user();

        // startOfSecond, not raw now(): the column is timestamp(0), so the
        // database would drop the microseconds anyway. Doing it here means the
        // timestamp handed back to the client is byte-for-byte the one that was
        // stored, and the count below compares against exactly what is on disk.
        $seenAt = Carbon::now()->startOfSecond();

        // Query builder rather than Eloquent, for the reason TouchLastActive
        // gives: it bypasses `updated_at`, model events and global scopes.
        // `updated_at` has to keep meaning "this record was edited" — opening a
        // screen is not an edit to the account.
        DB::table('users')
            ->where('id', $user->getKey())
            ->update(['activity_seen_at' => $seenAt]);

        // A query-builder write leaves the instance the guard is holding stale.
        // Nothing later in THIS request reads the marker today, but a stale
        // authenticated user is exactly the kind of thing that reads correctly
        // and answers wrongly later, so put it back in step. syncOriginal for
        // this one attribute only, so the model is not left dirty and a save
        // elsewhere cannot be widened by it.
        $user->setAttribute('activity_seen_at', $seenAt);
        $user->syncOriginalAttribute('activity_seen_at');

        return response()->json([
            'activity' => $this->payload((int) $user->getKey(), $seenAt),
        ]);
    }

    /**
     * How much activity I have not seen
     *
     * Points and carbon are counted separately AND totalled: the app may badge
     * them together or apart, and should not have to work out the sum itself.
     * A passenger who has never opened the screen has no marker, and everything
     * they have ever earned counts — "never looked" is not "nothing new".
     *
     * @authenticated
     *
     * @response 200 {"activity": {"seenAt": "2026-09-08T10:00:00+00:00", "unseen": {"loyalty": 2, "carbon": 1, "total": 3}}}
     * @response 200 {"activity": {"seenAt": null, "unseen": {"loyalty": 7, "carbon": 3, "total": 10}}}
     */
    public function unseenCount(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'activity' => $this->payload((int) $user->getKey(), $user->activity_seen_at),
        ]);
    }

    /**
     * The one body both endpoints answer with, so they cannot disagree.
     *
     * @return array{seenAt: ?string, unseen: array{loyalty: int, carbon: int, total: int}}
     *
     * THE TWO KEYS UNDER `unseen` ARE THE SAME TWO WORDS THE REST OF THE
     * SCREEN USES: `loyalty` and `carbon`, matching the activity feed's
     * `scheme` and the balance.changed socket event's `scheme`. This badge
     * said points/carbonCredits first; three names for two ledgers on one
     * screen is a bug waiting to be written on the client, where the compiler
     * cannot help. ActivityVocabularyTest pins all three surfaces together.
     *
     * camelCase, matching every other passenger-facing payload on this platform
     * — NotificationResource and the activity feed both do it, and the app's
     * models read camelCase with no transform layer. Two conventions in one
     * screen is how a client ends up carrying tolerance code for both.
     */
    private function payload(int $userId, ?Carbon $seenAt): array
    {
        $points = $this->countNewerThan(
            // withoutGlobalScopes for the reason every passenger-facing loyalty
            // query in this codebase carries it: LoyaltyTransaction is
            // BelongsToSacco, a passenger has users.sacco_id NULL, and SaccoScope
            // FAILS CLOSED on that with `1 = 0`. Scoped, this badge would read
            // zero for every passenger forever — which does not look like a bug,
            // it looks like "no new activity". user_id is the real boundary, and
            // it is applied below. Dropping BrandScope with it is deliberate too:
            // the badge must count exactly the rows the history endpoint lists,
            // and LoyaltyController::history is unscoped the same way.
            LoyaltyTransaction::withoutGlobalScopes()->where('user_id', $userId),
            $seenAt
        );

        // CarbonCreditTransaction carries no global scopes at all — carbon
        // credits are a platform balance with no sacco_id and no brand — so
        // there is nothing to strip here.
        $carbon = $this->countNewerThan(
            CarbonCreditTransaction::query()->where('user_id', $userId),
            $seenAt
        );

        return [
            'seenAt' => $seenAt?->toIso8601String(),
            'unseen' => [
                'loyalty' => $points,
                'carbon' => $carbon,
                'total' => $points + $carbon,
            ],
        ];
    }

    /**
     * Rows newer than the marker — or every row, when there is no marker.
     *
     * The strict `>` is the whole endpoint. `>=` would count an entry stamped in
     * the same second as the mark, and because both sides are stored to the
     * second that is not a rare tie: paying a fare and then opening Activity
     * inside the same second is the ordinary case, and it would put the badge
     * back the instant the passenger looked at it.
     */
    private function countNewerThan(Builder $ledger, ?Carbon $seenAt): int
    {
        if ($seenAt !== null) {
            $ledger->where('created_at', '>', $seenAt);
        }

        return $ledger->count();
    }
}
