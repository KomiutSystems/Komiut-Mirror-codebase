<?php

declare(strict_types=1);

namespace App\Http\Controllers\APIs\Dashboard\Saccos;

use App\Http\Controllers\Concerns\ResolvesTenant;
use App\Http\Controllers\Controller;
use App\Models\FarePeriod;
use App\Models\Scopes\SaccoScope;
use App\Services\Fares\FareResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * @group Fares
 *
 * The windows in which a SACCO charges a peak fare.
 *
 * A period is defined ONCE ("Morning peak, Mon–Fri, 06:00–09:00") and then
 * priced against as many segments as the SACCO likes through saccos/fares/add.
 * When the rush shifts, it moves here and every fare that references it moves
 * with it — which is the whole reason this is a table and not four hundred
 * copies of the same two times.
 *
 * Times are Kenyan wall-clock. FarePeriod::covers() is the only thing that
 * evaluates them, including the midnight wrap.
 */
class FarePeriodsController extends Controller
{
    use ResolvesTenant;

    public function __construct(private readonly FareResolver $fares)
    {
        $this->middleware('auth:sanctum');
    }

    /**
     * The SACCO's fare periods
     *
     * @authenticated
     */
    public function index(Request $request): JsonResponse
    {
        $saccoId = $this->resolveSaccoId($request);

        if ($saccoId === null) {
            return $this->foreignSaccoDenied();
        }

        $periods = FarePeriod::withoutGlobalScope(SaccoScope::class)
            ->where('sacco_id', $saccoId)
            ->orderByDesc('priority')
            ->orderBy('start_time')
            ->get();

        $now = now();

        return response()->json([
            'periods' => $periods->map(fn (FarePeriod $p) => $this->present($p, $p->covers($now)))->values(),
            // So the dashboard can say "Morning peak is live now" rather than
            // making an operator work it out from a table of times.
            'server_time' => $now->copy()->setTimezone(FarePeriod::TIMEZONE)->toIso8601String(),
            'timezone' => FarePeriod::TIMEZONE,
        ]);
    }

    /**
     * Create or update a fare period
     *
     * @authenticated
     *
     * @bodyParam id integer An existing period to update. Omit or 0 to create.
     * @bodyParam name string required What to call it. Example: Morning peak
     * @bodyParam days integer[] required ISO days, 1=Monday..7=Sunday. Example: [1,2,3,4,5]
     * @bodyParam start_time string required 24-hour HH:MM. Example: 06:00
     * @bodyParam end_time string required 24-hour HH:MM. Earlier than start means it wraps midnight. Example: 09:00
     * @bodyParam priority integer Higher wins where two periods overlap. Example: 10
     * @bodyParam status boolean Whether it is in force. Defaults to true.
     */
    public function save(Request $request): JsonResponse
    {
        $saccoId = $this->resolveSaccoId($request);

        if ($saccoId === null) {
            return $this->foreignSaccoDenied();
        }

        if (! auth()->user()->can('Add Fares') && ! auth()->user()->can('Edit Fares')) {
            return response()->json(['error' => 'You do not have permission to manage fares.'], 403);
        }

        $validator = Validator::make($request->all(), [
            'id' => 'nullable|integer|min:0',
            'name' => 'required|string|max:60',
            'days' => 'required|array|min:1|max:7',
            'days.*' => 'integer|between:1,7',
            // H:i so an operator can type 06:00. Seconds are meaningless for a
            // rush hour and would only be a second thing to get wrong.
            'start_time' => 'required|date_format:H:i',
            'end_time' => 'required|date_format:H:i|different:start_time',
            'priority' => 'nullable|integer|min:0|max:65535',
            'status' => 'nullable|boolean',
        ], [
            'days.*.between' => 'Days are 1 (Monday) to 7 (Sunday).',
            'end_time.different' => 'A period that starts and ends at the same time covers nothing.',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->messages()], 400);
        }

        $id = (int) $request->input('id', 0);

        if ($id > 0) {
            // Ownership explicitly, not via the scope: FarePeriod opts into
            // cross-tenant browsing so a tenantless caller would otherwise load
            // any SACCO's period and rewrite their pricing window.
            $period = FarePeriod::withoutGlobalScope(SaccoScope::class)
                ->where('id', $id)->where('sacco_id', $saccoId)->first();

            if ($period === null) {
                return response()->json(['error' => 'That fare period is not yours to edit.'], 404);
            }
        } else {
            $period = new FarePeriod();
            $period->sacco_id = $saccoId;
        }

        // Sorted and de-duplicated so [5,1,1] and [1,5] are the same stored
        // value — otherwise two identical periods compare unequal in the UI.
        $days = array_values(array_unique(array_map('intval', (array) $request->input('days'))));
        sort($days);

        $period->fill([
            'name' => trim((string) $request->input('name')),
            'days' => $days,
            'start_time' => $request->input('start_time').':00',
            'end_time' => $request->input('end_time').':00',
            'priority' => (int) $request->input('priority', 0),
            'status' => $request->boolean('status', true),
        ])->save();

        // Every route this SACCO prices may now cost something different, and
        // the bundles are cached per route — so there is no single key to drop.
        $this->fares->forgetSacco($saccoId);

        return response()->json([
            'period' => $this->present($period->fresh(), $period->covers(now())),
        ], $id > 0 ? 200 : 201);
    }

    /**
     * Delete a fare period
     *
     * Fares priced against it fall back to the segment's base fare. That is
     * reported rather than assumed — deleting the morning peak quietly making
     * every rush-hour journey cheaper is the kind of thing an operator should be
     * told to their face.
     *
     * @authenticated
     *
     * @bodyParam id integer required The period to remove. Example: 3
     */
    public function destroy(Request $request): JsonResponse
    {
        $saccoId = $this->resolveSaccoId($request);

        if ($saccoId === null) {
            return $this->foreignSaccoDenied();
        }

        if (! auth()->user()->can('Edit Fares')) {
            return response()->json(['error' => 'You do not have permission to manage fares.'], 403);
        }

        $validator = Validator::make($request->all(), ['id' => 'required|integer|min:1']);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->messages()], 400);
        }

        $period = FarePeriod::withoutGlobalScope(SaccoScope::class)
            ->where('id', (int) $request->input('id'))
            ->where('sacco_id', $saccoId)
            ->first();

        if ($period === null) {
            return response()->json(['error' => 'That fare period is not yours to remove.'], 404);
        }

        // Delete the prices that hang off it in the same breath. Left behind
        // they would be unreachable rows pointing at a period that no longer
        // exists, and a later period reusing the id would silently adopt them.
        $orphaned = \App\Models\RouteFare::withoutGlobalScope(SaccoScope::class)
            ->where('sacco_id', $saccoId)
            ->where('fare_period_id', $period->id)
            ->delete();

        $period->delete();

        $this->fares->forgetSacco($saccoId);

        return response()->json([
            'success' => 'Fare period removed.',
            'fares_removed' => $orphaned,
            'note' => $orphaned > 0
                ? "{$orphaned} peak price(s) went with it — those journeys now use their base fare."
                : null,
        ]);
    }

    /** @return array<string, mixed> */
    private function present(FarePeriod $p, bool $liveNow): array
    {
        return [
            'id' => (int) $p->id,
            'name' => $p->name,
            'days' => array_map('intval', (array) $p->days),
            'start_time' => substr((string) $p->getRawOriginal('start_time'), 0, 5),
            'end_time' => substr((string) $p->getRawOriginal('end_time'), 0, 5),
            'priority' => (int) $p->priority,
            'status' => (bool) $p->status,
            // A window whose end is before its start runs through midnight.
            'wraps_midnight' => (string) $p->getRawOriginal('end_time') < (string) $p->getRawOriginal('start_time'),
            'live_now' => $liveNow,
        ];
    }
}
