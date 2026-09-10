<?php

namespace App\Http\Controllers\APIs\Dashboard\Saccos;

use App\Http\Controllers\Concerns\ResolvesTenant;
use App\Http\Controllers\Controller;
use App\Models\LoyaltyProgram;
use App\Services\Platform\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * @group SACCO loyalty
 *
 * A SACCO configures its own loyalty program: the earn divisor (KES per point)
 * and the points needed for a free ride.
 */
class SaccoLoyaltyController extends Controller
{
    use ResolvesTenant;

    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }

    /**
     * Get the loyalty program
     *
     * @authenticated
     *
     * @queryParam sacco_id integer The SACCO; defaults to the caller's SACCO. Example: 1
     */
    public function show(Request $request)
    {
        $saccoId = $this->resolveSaccoId($request);
        if ($saccoId === null) {
            return $this->foreignSaccoDenied();
        }
        $program = LoyaltyProgram::where('sacco_id', $saccoId)->first();

        return response()->json(['program' => $program]);
    }

    /**
     * Create or update the loyalty program
     *
     * @authenticated
     *
     * @bodyParam sacco_id integer The SACCO; defaults to the caller's SACCO. Example: 1
     * @bodyParam divisor number required KES of fare per point earned (e.g. 100 = 1 point per KES 100). Example: 100
     * @bodyParam redemption_threshold number required Points needed to redeem a free ride. Example: 500
     * @bodyParam is_active boolean Whether the program is active. Example: true
     */
    public function save(Request $request)
    {
        // Same hazard as addFare, with a sharper edge: setting another SACCO's
        // redemption_threshold to 0 and divisor to 1 mints free rides on their buses.
        $saccoId = $this->resolveSaccoId($request);
        if ($saccoId === null) {
            return $this->foreignSaccoDenied();
        }

        $validator = Validator::make(array_merge($request->all(), ['sacco_id' => $saccoId]), [
            'sacco_id' => 'required|integer|min:1',
            'divisor' => 'required|numeric|min:1',
            'redemption_threshold' => 'required|numeric|min:0',
            'is_active' => 'boolean|nullable',
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->messages()], 400);
        }

        // Captured BEFORE the write, so the audit row can say what actually
        // changed rather than only what it changed to.
        $existing = LoyaltyProgram::withoutGlobalScopes()->where('sacco_id', $saccoId)->first();
        $before = $existing === null ? null : [
            'divisor' => (float) $existing->divisor,
            'redemption_threshold' => (float) $existing->redemption_threshold,
            'is_active' => (bool) $existing->is_active,
        ];

        $program = LoyaltyProgram::updateOrCreate(
            ['sacco_id' => $saccoId],
            [
                'divisor' => (float) $request->divisor,
                'redemption_threshold' => (float) $request->redemption_threshold,
                'is_active' => $request->has('is_active') ? (bool) $request->is_active : true,
            ],
        );

        // THIS IS THE ONLY LEVER A SACCO HAS OVER POINTS, SO IT IS THE ONE THAT
        // HAS TO BE ON THE RECORD.
        //
        // Nobody can hand points to a named passenger -- LoyaltyService::credit()
        // is private and reachable only from earning on a paid fare, and no route
        // exposes it. What a SACCO admin CAN do is change the terms for everyone:
        // `divisor` is KES of fare per point, so dropping it from 100 to 1 makes
        // every fare earn a hundred times more, and `redemption_threshold` is what
        // a free ride costs. Neither targets an individual, but both move real
        // value, and until now they moved it silently.
        //
        // Logged with before/after so the SACCO's own activity log answers "who
        // changed this, when, and from what" -- see ActivityLogController, which
        // shows it to anyone holding View Activity Log. No PII: the numbers and
        // the actor, nothing else.
        //
        // Wrapped because an audit failure must not fail the save. The change is
        // already committed by this point; throwing here would report an error for
        // a write that happened.
        try {
            AuditLogger::record(
                action: 'sacco.loyalty.changed',
                data: ['before' => $before, 'after' => [
                    'divisor' => (float) $program->divisor,
                    'redemption_threshold' => (float) $program->redemption_threshold,
                    'is_active' => (bool) $program->is_active,
                ]],
                subject: ['type' => 'loyalty_program', 'id' => (string) $program->id],
                saccoId: $saccoId,
            );
        } catch (\Throwable $e) {
            report($e);
        }

        return response()->json(['success' => 'Loyalty program saved.', 'program' => $program]);
    }
}
