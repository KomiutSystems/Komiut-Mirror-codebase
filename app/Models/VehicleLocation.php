<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToBrand;
use App\Models\Concerns\BelongsToFinancier;
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
    use HasFactory, BelongsToSacco, BelongsToBrand, BelongsToFinancier;

    /**
     * Readable by a caller with no SACCO of their own. See
     * BelongsToSacco::allowsCrossTenantBrowsing() — it exempts the TENANTLESS
     * caller only; a user who has a SACCO is still filtered to it.
     *
     * Where the buses are right now. The passenger app's whole roadside flow
     * reads this — find a matatu nearby, reserve a seat on one that is
     * broadcasting — and those buses are not from a SACCO the passenger belongs
     * to. Closed, every roadside reservation is refused as `not_broadcasting`.
     * The data is bus positions on public roads, and the staff live map that
     * shares this table stays scoped for anyone who HAS a SACCO.
     */
    protected bool $saccoCrossTenantBrowsing = true;

    /** Reaches the financed vehicle the same way $saccoVia does. */
    protected ?string $financierVia = 'vehicle';

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
