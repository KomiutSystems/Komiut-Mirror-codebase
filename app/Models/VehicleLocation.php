<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToBrand;
use App\Models\Concerns\BelongsToSacco;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * The CURRENT position of a vehicle — one row per vehicle (the table's
 * vehicle_id is unique), overwritten as it reports. This is not a track
 * history, so it answers "where is the fleet now" and cannot answer
 * "where has this bus been".
 *
 * Reaches both scopes through its vehicle, like Queue and Transaction: a
 * location row carries no sacco_id or brand of its own, and without these a
 * live-map read would return every SACCO's fleet to any admin who asked.
 */
class VehicleLocation extends Model
{
    use HasFactory, BelongsToSacco, BelongsToBrand;

    /** Reaches brand via vehicle. */
    protected ?string $brandVia = 'vehicle';

    /** Reaches sacco_id via the vehicle relation. */
    protected $saccoVia = 'vehicle';

    protected $fillable = [
        'vehicle_id', 'route_id', 'queue_id', 'latitude', 'longitude',
        'heading', 'broadcasting', 'recorded_at',
    ];

    protected $casts = [
        'latitude' => 'float',
        'longitude' => 'float',
        'heading' => 'integer',
        'broadcasting' => 'boolean',
        'recorded_at' => 'datetime',
    ];

    public function vehicle()
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function queue()
    {
        return $this->belongsTo(Queue::class);
    }

    /**
     * The route the vehicle is currently working, copied off the queue on each
     * ping. Needed so a live-map read can name the route instead of only
     * carrying its id — a passenger's screen cannot resolve a bare route_id.
     */
    public function route()
    {
        return $this->belongsTo(Route::class);
    }
}
