<?php

declare(strict_types=1);

namespace App\Http\Controllers\APIs\School;

use App\Http\Controllers\APIs\School\Concerns\ResolvesCallerSchool;
use App\Http\Controllers\Concerns\PaginatesResults;
use App\Http\Controllers\Controller;
use App\Models\VehicleFollower;
use App\Services\Platform\AuditLogger;
use App\Support\Phone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * @group School
 *
 * The parents following one of a school's buses, and removing one.
 *
 * The school sees as little about a parent as lets it recognise them: a name
 * and a masked phone. Never the full number, the email, or a pickup pin the
 * parent dropped themselves (that is usually their home) -- only which of the
 * school's own route stops they chose, if any. No child is ever named
 * anywhere. See docs/school-bus-tracking.md.
 */
class SchoolFollowersController extends Controller
{
    use PaginatesResults;
    use ResolvesCallerSchool;

    private const PER_PAGE = 50;

    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }

    /**
     * Parents following a bus
     *
     * Newest first, 50 per page.
     *
     * `stop` is the route stop the parent chose (`place_id`, `name`,
     * coordinates), or, when they dropped a pin of their own, only
     * `own_pin: true` with no coordinates; null when they set neither.
     *
     * @authenticated
     *
     * @urlParam vehicle integer required The bus. Example: 717
     *
     * @queryParam page integer Page number, 50 rows each. Default 1. Example: 1
     *
     * @response 200 {"followers":[{"id":31,"name":"Jane Wanjiru","phone_masked":"0712***678","stop":{"place_id":12,"name":"Kahawa West","latitude":-1.18,"longitude":36.92,"own_pin":false},"followed_at":"2026-11-26T06:10:00+00:00"}],"total":1,"per_page":50,"current_page":1,"last_page":1}
     * @response 403 scenario="caller's SACCO is not a school" {"error":"This is for school accounts."}
     * @response 404 scenario="not a bus in your school" {"error":"No such bus in your school."}
     */
    public function index(Request $request, int $vehicle): JsonResponse
    {
        $bus = $this->schoolBus($request, $vehicle);
        if ($bus instanceof JsonResponse) {
            return $bus;
        }

        $query = VehicleFollower::query()->where('vehicle_id', $bus->id);
        $meta = $this->pageMeta($query, $request, self::PER_PAGE, 0);

        $followers = $query
            ->with(['user:id,firstname,lastname,phone', 'stopPlace:id,name,latitude,longitude'])
            ->orderByDesc('id')
            ->skip(($meta['current_page'] - 1) * self::PER_PAGE)
            ->take(self::PER_PAGE)
            ->get()
            ->map(fn (VehicleFollower $f) => [
                'id' => (int) $f->id,
                'name' => trim(($f->user?->firstname ?? '').' '.($f->user?->lastname ?? '')) ?: null,
                'phone_masked' => self::maskPhone($f->user?->phone),
                'stop' => self::stop($f),
                'followed_at' => $f->created_at?->toIso8601String(),
            ])
            ->values();

        return response()->json(['followers' => $followers] + $meta);
    }

    /**
     * Remove a follower
     *
     * The parent stops seeing the bus at once. They can follow again only
     * with the current code, so a school removing someone it does not
     * recognise should also rotate the code. Audited.
     *
     * @authenticated
     *
     * @urlParam vehicle integer required The bus. Example: 717
     * @urlParam follower integer required The follower id from the list. Example: 31
     *
     * @response 204 scenario="removed"
     * @response 403 scenario="caller's SACCO is not a school" {"error":"This is for school accounts."}
     * @response 404 scenario="not a bus in your school" {"error":"No such bus in your school."}
     * @response 404 scenario="not a follower of this bus" {"error":"No such follower on this bus."}
     */
    public function destroy(Request $request, int $vehicle, int $follower): JsonResponse|Response
    {
        $bus = $this->schoolBus($request, $vehicle);
        if ($bus instanceof JsonResponse) {
            return $bus;
        }

        // Keyed by the bus as well as the id: a follower of another bus --
        // even another bus of this same school -- is not found here.
        $row = VehicleFollower::query()
            ->whereKey($follower)
            ->where('vehicle_id', $bus->id)
            ->first(['id', 'user_id', 'vehicle_id']);

        if ($row === null) {
            return response()->json(['error' => 'No such follower on this bus.'], 404);
        }

        DB::transaction(function () use ($row, $bus): void {
            $row->delete();

            // Ids only: AuditLogger payloads never carry names or phones.
            AuditLogger::record(
                'school.follower.removed',
                ['follower_id' => (int) $row->id, 'user_id' => (int) $row->user_id],
                subject: ['type' => 'vehicle', 'id' => (string) $bus->id],
                saccoId: (int) $bus->sacco_id,
            );
        });

        return response()->noContent();
    }

    /**
     * "0712***678": enough for a school to tell two parents apart or confirm
     * one on the phone, not enough to call a stranger.
     */
    private static function maskPhone(?string $phone): ?string
    {
        if ($phone === null || trim($phone) === '') {
            return null;
        }

        $local = Phone::normalise($phone) ?? (string) preg_replace('/\D+/', '', $phone);

        return strlen($local) >= 8
            ? substr($local, 0, 4).'***'.substr($local, -3)
            : '***';
    }

    /** @return array{place_id: ?int, name: ?string, latitude: ?float, longitude: ?float, own_pin: bool}|null */
    private static function stop(VehicleFollower $f): ?array
    {
        if ($f->stop_latitude !== null && $f->stop_longitude !== null) {
            return ['place_id' => null, 'name' => null, 'latitude' => null, 'longitude' => null, 'own_pin' => true];
        }

        $place = $f->stopPlace;
        if ($place === null) {
            return null;
        }

        return [
            'place_id' => (int) $place->id,
            'name' => $place->name,
            'latitude' => $place->latitude === null ? null : (float) $place->latitude,
            'longitude' => $place->longitude === null ? null : (float) $place->longitude,
            'own_pin' => false,
        ];
    }
}
