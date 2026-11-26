<?php

declare(strict_types=1);

namespace App\Services\Team\Kpis;

use App\Models\Team\TeamKpiTarget;
use App\Models\User;
use App\Services\Platform\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * The team's targets: one per KPI per kind of period, shared by everyone on
 * the team. Clearing a target keeps its row (target null) so the audit trail
 * and who last touched it survive.
 */
final class KpiTargets
{
    public function set(string $key, string $period, ?float $target, ?User $by): TeamKpiTarget
    {
        return DB::transaction(function () use ($key, $period, $target, $by): TeamKpiTarget {
            // createOrFirst: two people saving the first target for a KPI at
            // once must not trip the unique (key, period) into a 500. The lock
            // then makes "from" in the audit row the value this save replaced.
            $id = TeamKpiTarget::query()->createOrFirst(['key' => $key, 'period' => $period])->id;
            $row = TeamKpiTarget::query()->whereKey($id)->lockForUpdate()->firstOrFail();
            $was = $row->target;

            $row->forceFill(['target' => $target, 'updated_by' => $by?->id])->save();

            AuditLogger::record(
                'team.kpi_target.set',
                ['key' => $key, 'period' => $period, 'from' => $was, 'to' => $target],
                subject: ['type' => 'team_kpi_target', 'id' => $key.':'.$period],
            );

            return $row;
        });
    }
}
