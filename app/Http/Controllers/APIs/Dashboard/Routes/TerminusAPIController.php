<?php

namespace App\Http\Controllers\APIs\Dashboard\Routes;

use App\Http\Controllers\Concerns\PaginatesResults;
use App\Http\Controllers\Controller;
use App\Models\Place;
use App\Models\Route;
use App\Models\SaccoRoute;
use App\Models\SaccoTerminus;
use App\Models\Terminus;
use App\Services\Sql\LikeSql;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;

class TerminusAPIController extends Controller
{
    use PaginatesResults;

    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }

    public function getTermini(Request $request)
    {
        $page = $request->has('page') ? intval($request->page) : 1;
        $page--;
        $offset = $page * 20;
        $termini = Terminus::with('place')->when(filled($request->search), fn ($q) => $q->where('name', LikeSql::op(), '%'.$request->search.'%'));
        // The per-USER narrowing that used to sit here (terminus_users rows for
        // the caller) is gone. It filtered a SACCO-LEVEL admin list by one
        // person's assignments, and was inert only because terminus_users has
        // never held a row — the first row written would have silently emptied
        // this screen for exactly that one user. TerminusUser is being dropped
        // platform-wide; App\Services\Driver\AvailableTermini is the last reader.
        if (auth()->user()->sacco_id > 0) {
            $allowed = $this->saccoTerminusIds((int) auth()->user()->sacco_id);
            $termini = $allowed !== null
                ? $termini->whereIn('id', $allowed)
                // Nothing configured and nothing inferable — offer every terminus
                // that is in service rather than an empty screen.
                : $termini->where('status', true);
        }
        // Narrowing must be applied BEFORE this or `total` describes the
        // unfiltered set (see PaginatesResults).
        $__meta = $this->pageMeta($termini, $request, 20);
        $termini = $termini->skip($offset)->take(20)
            ->orderBy('name', 'ASC')->get();
        return response()->json(array_merge(['termini' => $termini], $__meta));
    }

    /**
     * The termini ids a SACCO's list should narrow to, or null when nothing
     * usable narrows it.
     *
     * `sacco_termini` fails CLOSED and has ZERO rows for all 48 SACCOs, so the
     * old single `whereIn(sacco_termini.terminus_id)` handed every SACCO on the
     * platform a blank terminus screen. This mirrors the same three tiers
     * App\Services\Driver\AvailableTermini already uses for the driver picker,
     * so both surfaces answer the same question the same way and both improve on
     * their own as the reference data lands:
     *
     *   1. The SACCO's OWN sacco_termini links. Configuration always wins.
     *   2. The origins of the SACCO's routes. Where a matatu's routes start IS
     *      where it queues, so a terminus there is a defensible guess.
     *   3. null → the caller falls through to every ACTIVE terminus. A slightly
     *      long list is workable; an empty one is not.
     *
     * Tier 1 does not filter on terminus.status: an explicit link is an explicit
     * answer, and hiding a suspended-but-linked terminus would change what
     * SACCOs with configuration already see. The inferred tiers do filter, since
     * a guess should only ever offer a terminus that is in service.
     *
     * @return Collection<int, int>|null
     */
    private function saccoTerminusIds(int $saccoId): ?Collection
    {
        $linked = SaccoTerminus::where('sacco_id', $saccoId)->pluck('terminus_id');
        if ($linked->isNotEmpty()) {
            return $linked;
        }

        // withoutGlobalScopes mirrors AvailableTermini: sacco_id is passed
        // explicitly here, so SaccoScope would only re-apply the same filter.
        $routeIds = SaccoRoute::withoutGlobalScopes()
            ->where('sacco_id', $saccoId)
            ->where('status', true)
            ->pluck('route_id');
        if ($routeIds->isEmpty()) {
            return null;
        }

        $origins = Route::withoutGlobalScopes()
            ->whereIn('id', $routeIds)
            ->pluck('from_id')
            ->filter()
            ->unique();
        if ($origins->isEmpty()) {
            return null;
        }

        $ids = Terminus::whereIn('place_id', $origins)->where('status', true)->pluck('id');

        // "This SACCO's origins have no terminus" is a real answer but not a
        // usable one — fall through rather than page an empty list.
        return $ids->isEmpty() ? null : $ids;
    }

    public function addTerminus(Request $request)
    {
        if (auth()->user()->can('Edit Termini') || auth()->user()->can('Add Termini')) {
            $validator = Validator::make($request->all(), [
                'id' => 'required|integer|min:0',
                'name' => 'required|string',
                'place' => 'required|string',
                'status' => 'required|integer|min:0|max:1',
            ]);

            if ($validator->fails()) {
                return response()->json(['errors' => $validator->messages()], 400);
            }
            $place = Place::where('name', $request->place)->first();
            if ($place == null) {
                return response()->json(['error' => 'Invalid place name provided!'], 401);
            }
            if (
                Terminus::where('name', $request->name)->where('place_id', $place->id)
                    ->where('id', '<>', $request->id)->count() > 0
            ) {
                return response()->json(['error' => 'Terminus already exists'], 401);
            }
            $terminus = new Terminus();
            if ($request->id > 0) {
                $terminus = Terminus::findOrFail($request->id);
            }
            $terminus->name = $request->name;
            $terminus->place_id = $place->id;
            $terminus->status = $request->status;
            if ($terminus->save()) {
                return response()->json(['success' => "Terminus updated successfully!"]);
            } else {
                return response()->json(['error' => 'Unable to update terminus'], 401);
            }
        } else {
            return response()->json(['error' => 'You do not have permissions for this action'], 401);
        }
    }
}
