<?php

declare(strict_types=1);

namespace App\Services\Sacco;

use App\Models\SaccoTerminus;
use App\Models\Terminus;
use Illuminate\Support\Collection;

/**
 * The stages a SACCO works.
 *
 * One definition, because two screens read it and they must agree: the crew
 * screen assigns a stage manager from this list, and the manager's own app is
 * answered from it. A terminus the dashboard cannot offer is a terminus the
 * app must not accept.
 */
final class SaccoStages
{
    /** @return Collection<int, array{id:int,name:string}> */
    public function forSacco(int $saccoId): Collection
    {
        $ids = SaccoTerminus::withoutGlobalScopes()
            ->where('sacco_id', $saccoId)
            ->where('status', true)
            ->pluck('terminus_id');

        return Terminus::with('place:id,name')->whereIn('id', $ids)->get(['id', 'name', 'place_id'])
            ->map(fn (Terminus $t) => ['id' => (int) $t->id, 'name' => $t->name ?: optional($t->place)->name])
            ->sortBy('name')->values();
    }

    /** @return array{id:int,name:string}|null */
    public function find(int $saccoId, ?int $terminusId): ?array
    {
        if ($terminusId === null) {
            return null;
        }

        return $this->forSacco($saccoId)->firstWhere('id', $terminusId);
    }
}
