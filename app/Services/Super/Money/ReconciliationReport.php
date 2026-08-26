<?php

declare(strict_types=1);

namespace App\Services\Super\Money;

/**
 * The answer to one run of the cross-system check.
 *
 * Deliberately carries the individual missing payments rather than only totals:
 * "76 missing" tells an operator that something is wrong, and nothing else. The
 * TransIDs are what makes it a work item — they can be looked up at Safaricom,
 * replayed, or handed to the till owner. The aggregates below are all derived
 * from that list so the summary and the detail can never disagree.
 */
final class ReconciliationReport
{
    /**
     * @param  array<int, array{transId:string, amount:float, shortcode:string, minute:string, arrived:bool}>  $missing
     */
    public function __construct(
        public readonly string $fromEat,
        public readonly string $toEat,
        public readonly int $legacyCount,
        public readonly float $legacyValue,
        public readonly int $localCount,
        public readonly float $localValue,
        public readonly array $missing,
        public readonly int $localOnlyCount,
        public readonly float $localOnlyValue,
        public readonly int $deficitMinutes,
        public readonly int $unattributableLogs,
        public readonly bool $truncated,
        public readonly string $legacyDescription = '',
    ) {}

    public function missingCount(): int
    {
        return count($this->missing);
    }

    public function missingValue(): float
    {
        return round(array_sum(array_column($this->missing, 'amount')), 2);
    }

    /**
     * Missing here, and no trace of it ever having been delivered. A TRANSPORT
     * problem: the confirmation never reached this host at all.
     *
     * @return array<int, array{transId:string, amount:float, shortcode:string, minute:string, arrived:bool}>
     */
    public function neverArrived(): array
    {
        return array_values(array_filter($this->missing, fn (array $m): bool => ! $m['arrived']));
    }

    /**
     * Missing here, but mpesa_logs holds the raw body — so it DID arrive and the
     * recording step dropped it. A RECORDING problem, and a completely different
     * team's afternoon.
     *
     * @return array<int, array{transId:string, amount:float, shortcode:string, minute:string, arrived:bool}>
     */
    public function arrivedNotRecorded(): array
    {
        return array_values(array_filter($this->missing, fn (array $m): bool => $m['arrived']));
    }

    /**
     * Missing payments grouped by till, worst first.
     *
     * This is usually the line that identifies the cause: a deficit spread evenly
     * across every shortcode is a systemic transport fault, while one concentrated
     * on a handful is a per-till registration problem.
     *
     * @return array<string, array{count:int, value:float}>
     */
    public function byShortcode(): array
    {
        $out = [];
        foreach ($this->missing as $m) {
            $code = $m['shortcode'] !== '' ? $m['shortcode'] : '(none)';
            $out[$code]['count'] = ($out[$code]['count'] ?? 0) + 1;
            $out[$code]['value'] = round(($out[$code]['value'] ?? 0.0) + $m['amount'], 2);
        }

        uasort($out, fn (array $a, array $b): int => $b['count'] <=> $a['count'] ?: $b['value'] <=> $a['value']);

        return $out;
    }

    /** Share of legacy's payments that never made it here. 0.0 when legacy is empty. */
    public function missingRatio(): float
    {
        return $this->legacyCount > 0 ? $this->missingCount() / $this->legacyCount : 0.0;
    }

    public function isClean(): bool
    {
        return $this->missingCount() === 0;
    }

    /**
     * Machine-readable form for the notification payload and the audit row.
     *
     * NO PII: TransIDs, shortcodes and amounts only. `mpesas` also holds
     * FirstName/MiddleName/LastName and MSISDN for every payer, and none of it
     * belongs in a notification that gets emailed and cached — see the note on
     * PlatformEvent.
     *
     * @return array<string, mixed>
     */
    public function toArray(int $sampleLimit = 20): array
    {
        return [
            'windowFromEat' => $this->fromEat,
            'windowToEat' => $this->toEat,
            'legacyCount' => $this->legacyCount,
            'legacyValue' => round($this->legacyValue, 2),
            'localCount' => $this->localCount,
            'localValue' => round($this->localValue, 2),
            'missingCount' => $this->missingCount(),
            'missingValue' => $this->missingValue(),
            'missingRatio' => round($this->missingRatio(), 5),
            'neverArrivedCount' => count($this->neverArrived()),
            'arrivedNotRecordedCount' => count($this->arrivedNotRecorded()),
            'localOnlyCount' => $this->localOnlyCount,
            'localOnlyValue' => round($this->localOnlyValue, 2),
            'deficitMinutes' => $this->deficitMinutes,
            'unattributableLogs' => $this->unattributableLogs,
            'truncated' => $this->truncated,
            'byShortcode' => array_slice($this->byShortcode(), 0, $sampleLimit, true),
            'sampleMissingTransIds' => array_slice(array_column($this->missing, 'transId'), 0, $sampleLimit),
            'legacySource' => $this->legacyDescription,
        ];
    }
}
