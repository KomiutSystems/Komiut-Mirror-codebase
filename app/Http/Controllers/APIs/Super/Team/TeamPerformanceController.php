<?php

declare(strict_types=1);

namespace App\Http\Controllers\APIs\Super\Team;

use App\Http\Controllers\Controller;
use App\Services\Team\TeamPerformance;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * GET super/team/performance?days=7|30|90 -- how the team works the board,
 * for the console's /super/kpis page. Definitions in TeamPerformance.
 */
class TeamPerformanceController extends Controller
{
    public function __invoke(Request $request, TeamPerformance $performance): JsonResponse
    {
        $data = $request->validate(['days' => ['sometimes', 'integer', Rule::in(TeamPerformance::DAYS)]]);

        return response()->json($performance->report((int) ($data['days'] ?? 30)));
    }
}
