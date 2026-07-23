<?php

namespace App\Http\Controllers\APIs\Dashboard\Saccos;

use App\Http\Controllers\Controller;
use App\Models\LoyaltyProgram;
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
        $saccoId = $request->filled('sacco_id') ? (int) $request->sacco_id : auth()->user()->currentSaccoId();
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
        $saccoId = $request->filled('sacco_id') ? (int) $request->sacco_id : auth()->user()->currentSaccoId();

        $validator = Validator::make(array_merge($request->all(), ['sacco_id' => $saccoId]), [
            'sacco_id' => 'required|integer|min:1',
            'divisor' => 'required|numeric|min:1',
            'redemption_threshold' => 'required|numeric|min:0',
            'is_active' => 'boolean|nullable',
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->messages()], 400);
        }

        $program = LoyaltyProgram::updateOrCreate(
            ['sacco_id' => $saccoId],
            [
                'divisor' => (float) $request->divisor,
                'redemption_threshold' => (float) $request->redemption_threshold,
                'is_active' => $request->has('is_active') ? (bool) $request->is_active : true,
            ],
        );

        return response()->json(['success' => 'Loyalty program saved.', 'program' => $program]);
    }
}
