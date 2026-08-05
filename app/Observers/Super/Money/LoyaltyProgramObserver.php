<?php

declare(strict_types=1);

namespace App\Observers\Super\Money;

use App\Models\LoyaltyProgram;
use App\Models\Sacco;
use App\Services\Platform\AuditLogger;
use App\Services\Platform\PlatformEvent;
use App\Services\Platform\PlatformNotifier;
use App\Services\Platform\Thresholds;
use Throwable;

/**
 * Event 5 — loyalty.program.extreme_config.
 *
 * A divisor below the per-brand floor mints points too fast (points ≈ fare /
 * divisor), and a zero redemption_threshold gives rides away for free. Either is
 * a config that can drain value, so it is flagged when the program is created or
 * its numbers change into/within extreme territory. AUDIT-FIRST (before/after).
 */
final class LoyaltyProgramObserver
{
    public function saved(LoyaltyProgram $program): void
    {
        // Guarded: the alert must never break the program save that triggered it.
        try {
            $this->emit($program);
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function emit(LoyaltyProgram $program): void
    {
        // Only react when the config itself moved (or was just created) — not on
        // an unrelated touch such as toggling is_active.
        $configMoved = $program->wasRecentlyCreated
            || $program->wasChanged('divisor')
            || $program->wasChanged('redemption_threshold');

        if (! $configMoved) {
            return;
        }

        $brand = $program->sacco_id !== null
            ? Sacco::withoutGlobalScopes()->whereKey($program->sacco_id)->value('brand')
            : null;
        $brand = $brand !== null ? (string) $brand : null;

        $floor = (float) Thresholds::get($brand, 'loyalty_divisor_floor');
        $divisor = (float) $program->divisor;
        $threshold = (float) $program->redemption_threshold;

        if ($divisor >= $floor && $threshold !== 0.0) {
            return;
        }

        $previous = [
            'divisor' => $program->getOriginal('divisor') !== null ? (float) $program->getOriginal('divisor') : null,
            'redemptionThreshold' => $program->getOriginal('redemption_threshold') !== null
                ? (float) $program->getOriginal('redemption_threshold')
                : null,
        ];

        $data = [
            'saccoId' => $program->sacco_id !== null ? (int) $program->sacco_id : null,
            'divisor' => $divisor,
            'redemptionThreshold' => $threshold,
            'previous' => $previous,
        ];

        $audit = AuditLogger::record(
            'loyalty.program.extreme_config',
            $data,
            null,
            ['type' => 'loyalty_program', 'id' => (string) $program->id],
            $brand,
        );

        app(PlatformNotifier::class)->dispatch(new PlatformEvent(
            event: 'loyalty.program.extreme_config',
            severity: 'high',
            class: 'alert',
            title: 'Extreme loyalty configuration',
            summary: 'Loyalty program set to divisor '.$divisor.', redemption threshold '.$threshold.'.',
            brand: $brand,
            actor: ['type' => $audit->actor_type, 'id' => $audit->actor_id, 'label' => $audit->actor_label],
            subject: ['type' => 'loyalty_program', 'id' => (string) $program->id],
            data: $data,
            windowMinutes: 0,
            auditId: $audit->id,
        ));
    }
}
