<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A parent (any passenger account) following a school bus.
 *
 * Deliberately carries NO tenant scope: it is the parent's own row, read by
 * the parent's own id, and written by the school only through endpoints that
 * first confine the bus to the school (see docs/school-bus-tracking.md).
 * Every read here must be keyed by user_id or by a vehicle the caller has
 * already been authorised for.
 */
class VehicleFollower extends Model
{
    /** How many buses one account may follow: a family, not a fleet. */
    public const MAX_PER_USER = 10;

    protected $fillable = [
        'user_id', 'vehicle_id', 'stop_place_id', 'stop_latitude', 'stop_longitude',
        'last_alerted_queue_id', 'last_alerted_at',
    ];

    protected $casts = [
        'stop_latitude' => 'float',
        'stop_longitude' => 'float',
        'last_alerted_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function stopPlace(): BelongsTo
    {
        return $this->belongsTo(Place::class, 'stop_place_id');
    }

    /** The parent's stop as [lat, lng]: their own pin first, else the route stop's place. Null when neither is set. */
    public function stopPoint(): ?array
    {
        if ($this->stop_latitude !== null && $this->stop_longitude !== null) {
            return [(float) $this->stop_latitude, (float) $this->stop_longitude];
        }

        $place = $this->stopPlace;

        return $place !== null && $place->latitude !== null && $place->longitude !== null
            ? [(float) $place->latitude, (float) $place->longitude]
            : null;
    }
}
