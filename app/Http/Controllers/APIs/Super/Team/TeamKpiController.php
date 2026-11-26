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

        $report = $this->kpis->report($data['period'] ?? KpiPeriod::WEEK);

        // Safetek Staff work the board but see no money: the platform's
        // collections are blanked for them after the (shared) cache, never in it.
        if (! $request->user()->isSuperAdmin()) {
            $report['kpis'] = array_map(fn (array $k) => $k['key'] === KpiCalculator::COLLECTED
                ? array_replace($k, ['value' => null, 'previous' => null, 'target' => null])
                : $k, $report['kpis']);
        }

        return response()->json($report);
    }

    /**
     * `period` (week|month, body or query) says which of the KPI's two targets
     * this is, and is required. Defaulting it to the week let a console that
     * edits the month's targets without sending it overwrite the team's shared
     * week target and report success; refusing it makes that console fail
     * loudly instead.
     */
    public function setTarget(Request $request, string $key): JsonResponse
    {
        // The team's targets are set by its Super Admins, not by staff.
        abort_unless($request->user()->isSuperAdmin(), 403, 'Only a Super Admin sets the team\'s targets.');

        if (! in_array($key, KpiCalculator::KEYS, true)) {
            return response()->json(['message' => 'No such KPI.'], 404);
        }

        $data = $request->validate([
            'target' => ['present', 'nullable', 'numeric', 'min:0',
                'max:'.(KpiCalculator::DEFINITIONS[$key]['unit'] === 'percent' ? 100 : self::MAX_TARGET)],
            'period' => ['required', 'string', Rule::in(KpiPeriod::KEYS)],
        ]);

        $period = $data['period'];
        $row = $this->targets->set($key, $period, $data['target'] === null ? null : (float) $data['target'], $request->user());

        return response()->json(['target' => ['key' => $key, 'period' => $period, 'target' => $row->target]]);
    }
}
