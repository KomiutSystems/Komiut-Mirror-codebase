<?php

declare(strict_types=1);

namespace App\Http\Controllers\APIs\Passenger;

use App\Http\Controllers\Concerns\PaginatesResults;
use App\Http\Controllers\Controller;
use App\Models\CarbonCreditTransaction;
use App\Models\LoyaltyTransaction;
use App\Models\Sacco;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * ONE chronological stream of everything a passenger has earned or spent,
 * across BOTH reward schemes.
 *
 * WHY THIS EXISTS. The app has a single Activity screen, and the backend had two
 * endpoints behind it: book_a_ride/loyalty/history returned a raw Eloquent
 * paginator of snake_case rows, carbon-credits/history returned a hand-mapped
 * array with its own page meta. The client had to merge two paginators of
 * different shapes, and could not: page 2 of one is not page 2 of the other, so
 * interleaving them by time across a page boundary is impossible on the client.
 * A passenger scrolling their history would see rows repeat and rows vanish.
 * The merge has to happen where the ORDER BY happens, which is here.
 *
 * The two schemes are genuinely different things and this endpoint does not
 * flatten them — see the item shape below. Loyalty is per-SACCO, funded by that
 * SACCO, denominated in fractional POINTS. Carbon credits are the platform's own
 * reward, held once across every SACCO and brand, denominated in whole CREDITS.
 * A row says which it is, in `scheme` and again in `unit`, so "50" is never
 * ambiguous.
 *
 * CASING. camelCase throughout, envelope included — the app's models are
 * camelCase and this codebase matches them rather than running a transform layer
 * (App\Http\Resources\NotificationResource is the reference). The two older
 * history endpoints are snake_case and stay that way; they have callers. This one
 * is new, so there is nothing to keep working, and half a response in each
 * convention would be worse than either.
 *
 * SCOPING. Both ledgers are read through the query builder, which carries no
 * Eloquent global scopes, and both are filtered on user_id — the only boundary
 * that means anything here. LoyaltyTransaction is a BelongsToSacco model and
 * SaccoScope FAILS CLOSED on a NULL sacco_id, which every passenger has; left
 * on, it would answer every passenger with an empty screen. That is the same
 * reason LoyaltyController::history and LoyaltyService::summary drop their
 * scopes at the call site rather than on the model.
 */
class PassengerActivityController extends Controller
{
    use PaginatesResults;

    /** The loyalty ledger: per-SACCO points, signed, fractional. */
    private const SCHEME_POINTS = 'points';

    /** The platform carbon ledger: whole credits, signed, no SACCO. */
    private const SCHEME_CARBON = 'carbon';

    public function __construct()
    {
        // NOT optional. A controller in the book_a_ride group without this is
        // authenticated in name only — the group's CheckAPIUserStatus then
        // reaches ->status on a null user. That shipped once already, on
        // PopularRoutesController, and signed passengers out of the app.
        $this->middleware('auth:sanctum');
    }

    /**
     * Activity feed
     *
     * The passenger's loyalty points and carbon credits in one stream, newest
     * first, paged as a single list.
     *
     * Every item carries the same keys whatever scheme it came from, so the app
     * can build a row without branching on presence — the scheme-specific ones
     * are simply null on the other side:
     *
     *     id           string   "points:41" / "carbon:7". The two ledgers have
     *                           overlapping integer ids, so a bare id would
     *                           collide in one list and a keyed ListView would
     *                           reuse the wrong row.
     *     scheme       string   "points" | "carbon" — which ledger this is.
     *     unit         string   "points" | "credits" — what to print after the
     *                           number.
     *     value        number   Signed, in `unit`. Read it as a `num`: points
     *                           are fractional and serialise as 12.5, credits
     *                           are whole and serialise as 3. That difference is
     *                           deliberate — they are not the same quantity.
     *     isCredit     bool     Does this ADD to the balance. Render the + / −
     *                           from this, never from `type`.
     *     type         string   Raw ledger type: earned | redeemed | reversed |
     *                           refunded (+ adjusted, carbon only).
     *     label        string   The line as a person would read it, from the
     *                           enum's own label().
     *     description  string   Carbon's free text (a manual grant's reason).
     *                           Null for points.
     *     saccoId      int      Points only — whose scheme earned it.
     *     saccoName    string   Points only.
     *     bookingId    int      The ride behind the row, if there was one.
     *     spendKsh     number   Carbon only — the travel that produced the row.
     *     createdAt    string   ISO 8601.
     *
     * @authenticated
     *
     * @queryParam scope string One of points, carbon, all. Default all. Example: all
     * @queryParam page integer Page number, from 1. Example: 1
     * @queryParam per_page integer Rows per page, 1-100. Default 20. Example: 20
     *
     * @response 200 {"activity":[{"id":"carbon:7","scheme":"carbon","unit":"credits","value":-2,"isCredit":false,"type":"redeemed","label":"Spent on a reward","description":null,"saccoId":null,"saccoName":null,"bookingId":null,"spendKsh":0.0,"createdAt":"2026-09-08T09:14:00+00:00"},{"id":"carbon:6","scheme":"carbon","unit":"credits","value":1,"isCredit":true,"type":"earned","label":"Earned by travelling","description":null,"saccoId":null,"saccoName":null,"bookingId":88,"spendKsh":300.0,"createdAt":"2026-09-08T08:02:00+00:00"},{"id":"points:41","scheme":"points","unit":"points","value":-500.0,"isCredit":false,"type":"reversed","label":"Reversed — ride refunded","description":null,"saccoId":3,"saccoName":"Nairobi CBD SACCO","bookingId":88,"spendKsh":null,"createdAt":"2026-09-08T08:02:00+00:00"}],"total":47,"perPage":20,"currentPage":1,"lastPage":3,"hasMore":true}
     */
    public function index(Request $request)
    {
        // `scope` is rejected rather than coerced: silently widening a client's
        // typo to "all" would show carbon rows on a screen that asked for points.
        $validator = Validator::make($request->all(), [
            'scope' => 'nullable|in:'.self::SCHEME_POINTS.','.self::SCHEME_CARBON.',all',
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->messages()], 400);
        }

        $userId = (int) auth()->id();
        $scope = (string) ($request->input('scope') ?: 'all');
        $wantsPoints = $scope !== self::SCHEME_CARBON;
        $wantsCarbon = $scope !== self::SCHEME_POINTS;

        // Clamped, not rejected: per_page is a cap, so an over-large ask gets
        // the cap rather than a 400.
        $perPage = $this->perPage($request, 20, 100);
        $page = max((int) $request->input('page', 1), 1);

        // Counted live, both halves. The cached count in PaginatesResults exists
        // for million-row tenant tables; a passenger's own ledger is tens of
        // rows on an indexed user_id, and a stale total right after they earn
        // would be a worse trade than the microseconds it saves.
        $total = ($wantsPoints ? $this->pointsLedger($userId)->count() : 0)
            + ($wantsCarbon ? $this->carbonLedger($userId)->count() : 0);

        $keys = $this->pageOfKeys($userId, $wantsPoints, $wantsCarbon, $perPage, ($page - 1) * $perPage);

        return response()->json([
            'activity' => $this->hydrate($keys, $userId),
            'total' => $total,
            'perPage' => $perPage,
            'currentPage' => $page,
            'lastPage' => (int) max((int) ceil($total / $perPage), 1),
            // Saves the client the currentPage < lastPage arithmetic on an
            // infinite-scroll screen, and stays right when total is 0.
            'hasMore' => $page * $perPage < $total,
        ]);
    }

    /** user_id IS the boundary. No global scopes on a query-builder query. */
    private function pointsLedger(int $userId): QueryBuilder
    {
        return DB::table('loyalty_transactions')->where('user_id', $userId);
    }

    private function carbonLedger(int $userId): QueryBuilder
    {
        return DB::table('carbon_credit_transactions')->where('user_id', $userId);
    }

    /**
     * One page of the merged stream, as (scheme, id, created_at) keys.
     *
     * WHY A UNION OF KEYS RATHER THAN OF ROWS. The two tables share almost no
     * columns — a double `value` against an integer `credits`, a sacco_id
     * against a description — so unioning the rows themselves means padding each
     * branch with NULLs of the other's type and letting Postgres pick a common
     * type for each position. That is exactly where "50 points" and "50 credits"
     * would quietly become the same number. The three columns below are already
     * type-identical in both tables, so the union needs no casts at all, and the
     * rows are then loaded through their own models with their own casts and
     * enums intact.
     *
     * WHY THERE ARE NO DUPLICATES AND NO GAPS ACROSS PAGES. OFFSET paging is
     * only correct when the ORDER BY is a TOTAL order: if two rows can tie, the
     * database may order them one way while serving page 1 and the other way
     * while serving page 2, and a row is then either returned twice or skipped.
     * `created_at` ties constantly here — the two ledgers are written in the same
     * request by the same paid ride, so a passenger's earn rows routinely share
     * a timestamp to the second across both tables. The tiebreak is
     * (scheme, id), and that pair is UNIQUE over the whole merged stream by
     * construction: `scheme` names the table and `id` is that table's primary
     * key. So (created_at, scheme, id) admits no ties, the order is total, and
     * every offset names the same row on every request.
     */
    private function pageOfKeys(int $userId, bool $wantsPoints, bool $wantsCarbon, int $limit, int $offset): Collection
    {
        $branches = [];

        // The scheme literal is interpolated from a private constant, never from
        // the request — the request only chooses WHICH branches are built.
        if ($wantsPoints) {
            $branches[] = $this->pointsLedger($userId)
                ->selectRaw("'".self::SCHEME_POINTS."' as scheme, id, created_at");
        }

        if ($wantsCarbon) {
            $branches[] = $this->carbonLedger($userId)
                ->selectRaw("'".self::SCHEME_CARBON."' as scheme, id, created_at");
        }

        /** @var QueryBuilder $query */
        $query = array_shift($branches);

        foreach ($branches as $branch) {
            $query = $query->unionAll($branch);
        }

        return collect(
            $query->orderByDesc('created_at')
                ->orderBy('scheme')
                ->orderByDesc('id')
                ->offset($offset)
                ->limit($limit)
                ->get()
        );
    }

    /**
     * Load the page's rows through their models and render them, IN THE UNION'S
     * ORDER — the two whereIn queries come back in whatever order the database
     * likes, so the merged order lives in $keys and nowhere else.
     *
     * @param  Collection<int, object>  $keys
     * @return array<int, array<string, mixed>>
     */
    private function hydrate(Collection $keys, int $userId): array
    {
        $pointIds = $keys->where('scheme', self::SCHEME_POINTS)->pluck('id')->all();
        $carbonIds = $keys->where('scheme', self::SCHEME_CARBON)->pluck('id')->all();

        // where('user_id') again, belt and braces: the ids came from a query that
        // was already filtered, and re-stating it means no future edit to the
        // key query can turn this into an unfiltered read by id.
        $points = $pointIds === []
            ? collect()
            : LoyaltyTransaction::withoutGlobalScopes()
                ->whereIn('id', $pointIds)->where('user_id', $userId)
                ->get()->keyBy('id');

        $carbon = $carbonIds === []
            ? collect()
            : CarbonCreditTransaction::whereIn('id', $carbonIds)
                ->where('user_id', $userId)
                ->get()->keyBy('id');

        // SACCO names in one query with scopes dropped, rather than eager-loaded
        // off the transaction. `->with('sacco:id,name')` looks equivalent and is
        // not: withoutGlobalScopes() applies to the builder it is called on, and
        // the eager load builds a FRESH Sacco query that gets SaccoScope back —
        // which fails closed for a passenger and returns a null SACCO on every
        // row. LoyaltyService::summary resolves names the same way for the same
        // reason. BrandScope is dropped too: saccos.brand is the SACCO's primary
        // brand and explicitly not authoritative (NICCO runs 126 komiut buses and
        // 54 safiri), so it must not decide whether a name resolves.
        $saccoNames = $points->isEmpty()
            ? collect()
            : Sacco::withoutGlobalScopes()
                ->whereIn('id', $points->pluck('sacco_id')->filter()->unique()->all())
                ->pluck('name', 'id');

        $items = [];

        foreach ($keys as $key) {
            $scheme = (string) $key->scheme;
            $row = $scheme === self::SCHEME_POINTS
                ? $points->get($key->id)
                : $carbon->get($key->id);

            if ($row === null) {
                // Deleted between the key query and this one. Skipping keeps the
                // page short by one rather than emitting a half-rendered row.
                continue;
            }

            $items[] = $scheme === self::SCHEME_POINTS
                ? $this->pointsItem($row, $saccoNames)
                : $this->carbonItem($row);
        }

        return $items;
    }

    /**
     * A loyalty row.
     *
     * THE SIGN COMES FROM THE TYPE, which is where LoyaltyTransactionType says
     * it lives. `reversed` is the case that matters: it reads like an undo and it
     * is a DEBIT — an earn taken back when a paid ride is refunded — so a client
     * inferring direction from the string gets it backwards. LoyaltyService
     * already writes `value` with the matching sign, so normalising it here is a
     * no-op on every row it wrote and a repair on any row it did not.
     *
     * @param  Collection<int, string>  $saccoNames
     * @return array<string, mixed>
     */
    private function pointsItem(LoyaltyTransaction $t, Collection $saccoNames): array
    {
        $isCredit = $t->type->isCredit();
        $magnitude = abs((float) $t->value);

        return [
            'id' => self::SCHEME_POINTS.':'.$t->id,
            'scheme' => self::SCHEME_POINTS,
            // `unit` IS THE CONTRACT, NOT THE JSON NUMBER TYPE. value is a
            // float here, but json_encode drops a whole-number float's fraction
            // without JSON_PRESERVE_ZERO_FRACTION -- 300.0 goes out as 300 --
            // so a client cannot tell points from credits by looking at whether
            // the number has a decimal point. It has to read `unit`.
            'unit' => 'points',
            'value' => $isCredit ? $magnitude : -$magnitude,
            'isCredit' => $isCredit,
            'type' => $t->type->value,
            'label' => $t->type->label(),
            'description' => null,
            'saccoId' => $t->sacco_id === null ? null : (int) $t->sacco_id,
            'saccoName' => $saccoNames[$t->sacco_id] ?? null,
            'bookingId' => $t->booking_id === null ? null : (int) $t->booking_id,
            'spendKsh' => null,
            'createdAt' => optional($t->created_at)->toIso8601String(),
        ];
    }

    /**
     * A carbon row.
     *
     * THE SIGN COMES FROM THE ROW here, not from the type, and the asymmetry with
     * points above is deliberate rather than an oversight. CarbonCreditType says
     * so itself: "The sign lives on the row; this names the reason" — and it has
     * to, because `adjusted` is a platform correction that can go either way. So
     * CarbonCreditType has no isCredit() to call, and inventing one would have to
     * guess at `adjusted`. The stored integer already knows.
     *
     * @return array<string, mixed>
     */
    private function carbonItem(CarbonCreditTransaction $t): array
    {
        $credits = (int) $t->credits;

        return [
            'id' => self::SCHEME_CARBON.':'.$t->id,
            'scheme' => self::SCHEME_CARBON,
            'unit' => 'credits',
            'value' => $credits,
            // A zero-credit adjustment adds nothing and takes nothing; calling it
            // a debit would draw a red minus against "0".
            'isCredit' => $credits >= 0,
            'type' => $t->type->value,
            'label' => $t->type->label(),
            'description' => $t->description,
            'saccoId' => null,
            'saccoName' => null,
            'bookingId' => $t->booking_id === null ? null : (int) $t->booking_id,
            'spendKsh' => round(((int) $t->spend_cents) / 100, 2),
            'createdAt' => optional($t->created_at)->toIso8601String(),
        ];
    }
}
