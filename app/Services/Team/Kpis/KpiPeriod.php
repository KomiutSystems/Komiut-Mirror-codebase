<?php

declare(strict_types=1);

namespace App\Services\Team\Kpis;

use App\Support\BusinessDay;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * A KPI period on the operator's calendar: the Monday-to-Sunday week, or the
 * calendar month, of the current Nairobi BUSINESS day.
 *
 * Business day, not calendar day, because that is how the takings are filed
 * (App\Support\BusinessDay): at 01:00 on a Monday the night's last runs still
 * belong to Sunday, so the board still shows the week they belong to.
 *
 * Two shapes of bound, because the data has two shapes:
 *   - dates (from/to, both inclusive) for columns that hold a Nairobi date,
 *     like summaries.trans_date;
 *   - instants (startsAt/endsAt, half-open) for UTC timestamps, like
 *     team_threads.first_inbound_at: 03:00 EAT on the first day to 03:00 EAT
 *     after the last.
 */
final class KpiPeriod
{
    public const WEEK = 'week';

    public const MONTH = 'month';

    public const KEYS = [self::WEEK, self::MONTH];

    private function __construct(
        public readonly string $key,
        public readonly CarbonImmutable $from,
        public readonly CarbonImmutable $to,
    ) {}

    /** The period of this kind that contains the business day running at $at (default: now). */
    public static function current(string $key, ?CarbonInterface $at = null): self
    {
        return self::containing($key, BusinessDay::current($at));
    }

    /** The whole period of the same kind just before this one. */
    public function previous(): self
    {
        return self::containing($this->key, $this->from->subDay());
    }

    public function fromDate(): string
    {
        return $this->from->toDateString();
    }

    public function toDate(): string
    {
        return $this->to->toDateString();
    }

    /** 03:00 EAT on the first day, in UTC: the inclusive start for a timestamp column. */
    public function startsAt(): CarbonImmutable
    {
        return $this->from->addHours(BusinessDay::START_HOUR)->utc();
    }

    /** 03:00 EAT after the last day, in UTC: the exclusive end for a timestamp column. */
    public function endsAt(): CarbonImmutable
    {
        return $this->to->addDay()->addHours(BusinessDay::START_HOUR)->utc();
    }

    /** @return array{key: string, from: string, to: string} */
    public function toArray(): array
    {
        return ['key' => $this->key, 'from' => $this->fromDate(), 'to' => $this->toDate()];
    }

    private static function containing(string $key, CarbonImmutable $day): self
    {
        // Nairobi midnight of the business date, so dates read back as filed.
        $day = $day->setTimezone(BusinessDay::TIMEZONE)->startOfDay();

        return match ($key) {
            self::WEEK => new self($key, $day->startOfWeek(CarbonInterface::MONDAY), $day->endOfWeek(CarbonInterface::SUNDAY)->startOfDay()),
            self::MONTH => new self($key, $day->startOfMonth(), $day->endOfMonth()->startOfDay()),
            default => throw new InvalidArgumentException("Unknown KPI period: {$key}"),
        };
    }
}
