<?php

declare(strict_types=1);

namespace App\Http\Controllers\APIs\Buses;

use App\Http\Controllers\Controller;
use App\Services\Buses\SavedBuses;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Validator;

/**
 * @group Saved buses
 *
 * A passenger -- any passenger, a parent watching a school bus or a commuter
 * watching their usual matatu -- saves buses in the app. The list lives on the
 * phone; the server keeps no record of who saved what. The app finds a bus by
 * plate once and keeps the `key` that comes back, then asks where its saved
 * buses are by key, and while one is open on the map listens on its
 * `bus.{key}` channel and works out its own arrival estimate from the position
 * and `speed_kmh`. The key is the only handle: a bare id reads nothing.
 *
 * Open to any signed-in user who knows the plate, school buses included: a
 * product decision. A matatu is seen only from its own brand's app, as on
 * the nearby map; a school bus from either. See docs/school-bus-tracking.md.
 */
class SavedBusesController extends Controller
{
    /** Raw entries accepted before duplicates collapse: bounds the parsing, not the answer. */
    private const MAX_RAW_KEYS = 100;

    public function __construct(private readonly SavedBuses $buses)
    {
        $this->middleware('auth:sanctum');
    }

    /**
     * Find a bus by plate
     *
     * Exact plate match, however it is typed ("ksb101a" finds "KSB 101A").
     * Buses in service only (the bus and its SACCO or school switched on), at
     * most 5. Never a position: ask `buses/live` with the `key`, which the app
     * saves with the bus. The key never changes for a bus.
     *
     * `operator.kind` is `sacco` for a matatu and `school` for a school bus.
     *
     * @authenticated
     *
     * @queryParam plate string required The number plate, 3-15 characters. Example: KSB 101A
     *
     * @response 200 {"buses": [{"id": 961, "key": "961-4f0c2a9be81d7730c15e", "plate": "KSB 101A", "fleet_no": "GB1", "operator": {"id": 100013, "name": "Greenfield Demo Academy", "kind": "school"}}]}
     * @response 200 scenario="no bus has that plate" {"buses": []}
     * @response 422 {"errors": {"plate": ["The plate field must be at least 3 characters."]}}
     */
    public function search(Request $request): JsonResponse
    {
        $validator = Validator::make($request->query(), ['plate' => 'required|string|min:3|max:15']);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->messages()], 422);
        }

        return response()->json([
            'buses' => $this->buses->search((string) $request->query('plate'), $request->user(), $this->brand()),
        ]);
    }

    /**
     * Where my saved buses are
     *
     * The app's saved list, by the `key` buses/search gave for each bus:
     * `keys=961-4f0c...,962-...` or `keys[]=...&keys[]=...`, 1 to 20 distinct
     * keys (a repeated key counts once). Answered in the order asked.
     *
     * `live` is the bus's position only while it is broadcasting a fresh fix
     * (the same window as everywhere else in the app, 2 minutes); otherwise
     * null. `speed_kmh` is the bus's own smoothed speed, null until known.
     * `trip` is the live run that position belongs to, if any. `channel` is
     * the private channel to subscribe to for every new position
     * (`vehicle.moved`, via /broadcasting/auth, sending the app's X-App-Key).
     *
     * `missing` lists the keys not answered: not a key this server made,
     * unknown, switched off, or another brand's matatu. The app may drop them
     * from its list, or find the bus by plate again.
     *
     * @authenticated
     *
     * @queryParam keys string required Comma-separated bus keys from buses/search, 1-20. Example: 961-4f0c2a9be81d7730c15e
     *
     * @response 200 {"buses": [{"id": 961, "key": "961-4f0c2a9be81d7730c15e", "plate": "KSB 101A", "fleet_no": "GB1", "operator": {"id": 100013, "name": "Greenfield Demo Academy", "kind": "school"}, "live": {"latitude": -1.1932, "longitude": 36.9353, "heading": 74, "speed_kmh": 28.5, "recorded_at": "2026-11-27T06:42:10+03:00", "age_seconds": 4}, "trip": {"queue_id": 5521, "route": {"id": 88, "name": "Greenfield morning run"}}, "channel": "private-bus.961-4f0c2a9be81d7730c15e"}], "missing": ["962-0a1b2c3d4e5f60718293"]}
     * @response 422 {"errors": {"keys": ["Send between 1 and 20 bus keys."]}}
     */
    public function live(Request $request): JsonResponse
    {
        $keys = $this->keys($request->query('keys'));
        if (is_string($keys)) {
            return response()->json(['errors' => ['keys' => [$keys]]], 422);
        }

        return response()->json($this->buses->live($keys, $request->user(), $this->brand()));
    }

    /**
     * The distinct keys asked for, in order, or the reason they cannot be read.
     * Only their shape is checked here; whether this server made them is the
     * service's question, and a key it did not make is merely `missing`.
     *
     * @return list<string>|string
     */
    private function keys(mixed $raw): array|string
    {
        $tooMany = 'Send between 1 and '.SavedBuses::MAX_KEYS.' bus keys.';
        $malformed = 'Every key must be a bus key from buses/search.';

        if (is_string($raw)) {
            $raw = [$raw];
        }
        if (! is_array($raw)) {
            return $tooMany;
        }

        $parts = [];
        foreach ($raw as $entry) {
            if (! is_string($entry)) {
                return $malformed;
            }
            foreach (explode(',', $entry) as $part) {
                // A trailing comma ("961-...,") is not a key, and not an error.
                if (trim($part) === '') {
                    continue;
                }
                $parts[] = strtolower(trim($part));
                if (count($parts) > self::MAX_RAW_KEYS) {
                    return $tooMany;
                }
            }
        }

        $keys = [];
        foreach ($parts as $part) {
            if (preg_match(SavedBuses::KEY_PATTERN, $part) !== 1) {
                return $malformed;
            }
            $keys[$part] = true;
        }

        if ($keys === [] || count($keys) > SavedBuses::MAX_KEYS) {
            return $tooMany;
        }

        return array_map('strval', array_keys($keys));
    }

    /** The brand of the app the request came from (ResolveBrand), if any. */
    private function brand(): ?string
    {
        $brand = Context::get('brand');

        return is_string($brand) && $brand !== '' ? $brand : null;
    }
}
