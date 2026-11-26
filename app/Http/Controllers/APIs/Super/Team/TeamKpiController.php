<?php

declare(strict_types=1);

namespace App\Http\Controllers\APIs\Super\Team;

use App\Http\Controllers\Controller;
use App\Services\Team\Kpis\KpiCalculator;
use App\Services\Team\Kpis\KpiPeriod;
use App\Services\Team\Kpis\KpiTargets;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The KPI strip on the ops board, and the team's targets for it.
 *
 * Behind the team-workspace gate like the rest of /super/team; the figures are
 * platform-wide, both brands together.
 */
class TeamKpiController extends Controller
{
    /** decimal(16,2) holds 14 whole digits. */
    private const MAX_TARGET = '99999999999999';

    public function __construct(
        private readonly KpiCalculator $kpis,
        private readonly KpiTargets $targets,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'period' => ['sometimes', 'string', Rule::in(KpiPeriod::KEYS)],
        ]);

        return response()->json($this->kpis->report($data['period'] ?? KpiPeriod::WEEK));
    }

    /**
     * `period` (week|month, body or query) says which of the KPI's two targets
     * this is. The console's targets sheet is per period but does not send it
     * yet, so without one it is the week's -- the board's default view.
     */
    public function setTarget(Request $request, string $key): JsonResponse
    {
        if (! in_array($key, KpiCalculator::KEYS, true)) {
            return response()->json(['message' => 'No such KPI.'], 404);
        }

        $data = $request->validate([
            'target' => ['present', 'nullable', 'numeric', 'min:0',
                'max:'.(KpiCalculator::DEFINITIONS[$key]['unit'] === 'percent' ? 100 : self::MAX_TARGET)],
            'period' => ['sometimes', 'string', Rule::in(KpiPeriod::KEYS)],
        ]);

        $period = $data['period'] ?? KpiPeriod::WEEK;
        $row = $this->targets->set($key, $period, $data['target'] === null ? null : (float) $data['target'], $request->user());

        return response()->json(['target' => ['key' => $key, 'period' => $period, 'target' => $row->target]]);
    }
}
