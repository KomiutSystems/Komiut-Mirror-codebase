<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToSacco;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A named window in which a SACCO charges a different fare — the morning rush,
 * the evening rush, the late-night rate.
 *
 * Defined ONCE per SACCO and priced against many segments, rather than every
 * fare row carrying its own copy of the same window. When the rush shifts, a
 * SACCO moves it here and every fare that references it moves with it.
 */
class FarePeriod extends Model
{
    use HasFactory, BelongsToSacco;

    /**
     * Kenyan wall-clock. "The 6am rush" means 6am in Nairobi, not 6am UTC, and
     * this system already has one EAT-vs-UTC trap in it (mpesas.TransTime is EAT
     * wall-clock while created_at is UTC) that cost a reconciliation. Naming the
     * zone here means nothing downstream has to remember.
     */
    public const TIMEZONE = 'Africa/Nairobi';

    protected $fillable = ['sacco_id', 'name', 'days', 'start_time', 'end_time', 'priority', 'status'];

    protected $casts = [
        'days' => 'array',
        'priority' => 'integer',
        'status' => 'boolean',
    ];

    /**
     * Readable by a caller with no SACCO of their own. See
     * BelongsToSacco::allowsCrossTenantBrowsing().
     *
     * Catalogue: a passenger is quoted a peak fare and is entitled to see why —
     * "Morning peak, 06:00–09:00" is the explanation. It carries no private
     * data, only the SACCO's published pricing windows.
     */
    protected bool $saccoCrossTenantBrowsing = true;

    public function sacco(): BelongsTo
    {
        return $this->belongsTo(Sacco::class);
    }

    /**
     * Does this window contain the given moment?
     *
     * THE ONE PLACE that understands overnight windows. `end_time` earlier than
     * `start_time` means the window wraps midnight — 21:00 to 05:00 is the
     * late-night rate — and the window belongs to the day it STARTS on. So at
     * 02:00 on Tuesday the active late-night window is Monday's, and a period
     * listing only Monday must still cover it.
     *
     * Nothing else may re-derive this. A second implementation that forgets the
     * wrap would quietly charge the day rate at 2am.
     */
    public function covers(CarbonInterface $moment): bool
    {
        if (! $this->status) {
            return false;
        }

        $local = $moment->copy()->setTimezone(self::TIMEZONE);
        $days = array_map('intval', (array) $this->days);

        if ($days === []) {
            return false;
        }

        $now = $local->format('H:i:s');
        $start = $this->timeString($this->start_time);
        $end = $this->timeString($this->end_time);

        // ISO-8601: 1 = Monday .. 7 = Sunday.
        $today = (int) $local->isoWeekday();
        $yesterday = $today === 1 ? 7 : $today - 1;

        if ($start <= $end) {
            // Ordinary same-day window. End is EXCLUSIVE so two adjacent windows
            // (06:00–09:00 and 09:00–12:00) cannot both claim 09:00:00.
            return in_array($today, $days, true) && $now >= $start && $now < $end;
        }

        // Wraps midnight: either we are after the start on a listed day, or
        // before the end on the morning after a listed day.
        return (in_array($today, $days, true) && $now >= $start)
            || (in_array($yesterday, $days, true) && $now < $end);
    }

    /**
     * Times come back as 'HH:MM:SS' from PostgreSQL but a Carbon instance from a
     * model that has been freshly filled, and string comparison only works on
     * the former. Normalising here keeps covers() honest either way.
     */
    private function timeString(mixed $value): string
    {
        if ($value instanceof CarbonInterface) {
            return $value->format('H:i:s');
        }

        $value = (string) $value;

        // 'HH:MM' -> 'HH:MM:SS' so lexical comparison stays valid.
        return substr_count($value, ':') === 1 ? $value . ':00' : $value;
    }
}
