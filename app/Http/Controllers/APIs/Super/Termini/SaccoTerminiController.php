<?php

declare(strict_types=1);

namespace App\Http\Controllers\APIs\Super\Termini;

use App\Http\Controllers\Controller;
use App\Http\Resources\Super\SlimPage;
use App\Models\Sacco;
use App\Models\SaccoTerminus;
use App\Models\Terminus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * The write surface for `sacco_termini` — which termini a SACCO operates out of.
 *
 * The table had THREE readers and ZERO writers: TerminusAPIController's SACCO
 * terminus list, QueuesAPIController::getGeofence and this console's own
 * RoutesTerminiController brand filter. All three fail closed, and the table
 * holds no rows for any of the 48 SACCOs — so the reference surface could
 * already CREATE a Terminus, but the terminus was then invisible to every
 * SACCO on the platform because nothing ever linked it to one.
 *
 * Two schema decisions this makes, both deliberate:
 *
 * `user_id` is NOT NULL with no default and a platform operator acting FOR a
 * SACCO has no natural value for it. It records the ACTING OPERATOR rather than
 * being made nullable: that is already what the column means everywhere else it
 * is written (IndexApiController's legacy copy, and the sibling `sacco_routes`
 * pivot), it keeps "who linked this" answerable on a privileged cross-tenant
 * write, and it avoids a migration on a database with no backups.
 *
 * `status` is deliberately NOT on this write surface. It defaults true and
 * neither SACCO-side reader filters on it, so a link "deactivated" through here
 * would still appear in the terminus list AND the geofence payload — a control
 * that silently does nothing. Detach therefore DELETES the link row: this is a
 * pure pivot carrying one setting (`geofence_radius`), nothing holds a foreign
 * key to it, and removing it is the only "unlinked" state all three readers
 * already agree on. (Making status meaningful instead would mean teaching the
 * geofence reader and this console's raw `DB::table('sacco_termini')` brand
 * filter about it — see handoff notes.)
 */
final class SaccoTerminiController extends Controller
{
    /** What a SACCO is currently linked to, so the console can render a detach control. */
    public function index(Request $request, int $id): JsonResponse
    {
        $sacco = Sacco::find($id);
        if ($sacco === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $perPage = max(1, min((int) $request->input('per_page', 25), 100));

        $paginator = SaccoTerminus::withoutGlobalScopes()
            ->with('terminus.place:id,name')
            ->where('sacco_id', $sacco->id)
            ->orderBy('terminus_id')
            ->paginate($perPage)
            ->appends($request->query());

        return SlimPage::of($paginator, fn (SaccoTerminus $link): array => $this->row($link))->response();
    }

    /**
     * Link a terminus to a SACCO, or update the radius on a link that exists.
     *
     * Idempotent by (sacco_id, terminus_id): the pair carries only an index, not
     * a unique constraint — production may already hold duplicate pairs, so the
     * index migration could not declare one — which means a plain insert here
     * would quietly double up every time an operator clicked twice.
     */
    public function attach(Request $request, int $id): JsonResponse
    {
        $sacco = Sacco::find($id);
        if ($sacco === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        try {
            $validated = $request->validate([
                'terminus_id' => ['required', 'integer', 'exists:termini,id'],
                // `geofence_radius` is `double unsigned` in Postgres: a negative
                // value would reach the driver and surface as a 500, not a 422.
                'geofence_radius' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            ]);
        } catch (ValidationException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => $e->errors()], 422);
        }

        // Only touch the radius when the caller actually sent one — re-attaching
        // to fix a typo elsewhere must not silently wipe a configured geofence.
        $attributes = ['user_id' => $request->user()->id];
        if ($request->has('geofence_radius')) {
            $attributes['geofence_radius'] = $validated['geofence_radius'] ?? null;
        }

        // withoutGlobalScopes: SaccoScope exempts super admins already, but this
        // is a cross-tenant write by definition, so it does not rely on that.
        $link = SaccoTerminus::withoutGlobalScopes()->updateOrCreate(
            ['sacco_id' => $sacco->id, 'terminus_id' => (int) $validated['terminus_id']],
            $attributes,
        );

        return response()->json([
            'success' => true,
            'link' => $this->row($link->load('terminus.place:id,name')),
        ], $link->wasRecentlyCreated ? 201 : 200);
    }

    /**
     * Unlink a terminus from a SACCO.
     *
     * Reports how many rows went rather than 404-ing on an already-detached
     * link: detaching twice is the same click twice, not an error, and the
     * count is also how an operator learns a legacy duplicate pair existed.
     */
    public function detach(int $id, int $terminus): JsonResponse
    {
        $sacco = Sacco::find($id);
        if ($sacco === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $detached = SaccoTerminus::withoutGlobalScopes()
            ->where('sacco_id', $sacco->id)
            ->where('terminus_id', $terminus)
            ->delete();

        return response()->json(['success' => true, 'detached' => $detached]);
    }

    /** @return array<string, mixed> */
    private function row(SaccoTerminus $link): array
    {
        /** @var Terminus|null $terminus */
        $terminus = $link->terminus;

        return [
            'id' => $link->id,
            'sacco_id' => $link->sacco_id,
            'terminus' => $terminus !== null ? [
                'id' => $terminus->id,
                'name' => $terminus->name,
                'place' => $terminus->place !== null
                    ? ['id' => $terminus->place->id, 'name' => $terminus->place->name]
                    : null,
                'status' => (bool) $terminus->status,
            ] : null,
            'geofence_radius' => $link->geofence_radius !== null ? (float) $link->geofence_radius : null,
            'linked_by' => $link->user_id,
            'created_at' => optional($link->created_at)->toIso8601String(),
        ];
    }
}
