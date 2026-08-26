<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToBrand;
use App\Models\Concerns\BelongsToSacco;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A SACCO-set fare for one pickup→dropoff pair on one of its routes.
 * Read through the cached App\Services\Fares\FareResolver — do not query this
 * directly on the hot path.
 */
class RouteFare extends Model
{
    use HasFactory, BelongsToSacco, BelongsToBrand;

    /**
     * Readable by a caller with no SACCO of their own. See
     * BelongsToSacco::allowsCrossTenantBrowsing() for what this does and does
     * not permit — it exempts the TENANTLESS caller only; a user who has a
     * SACCO is still filtered to it.
     *
     * What it costs. A fare a passenger cannot read is a fare they cannot agree
     * to pay.
     */
    protected bool $saccoCrossTenantBrowsing = true;

    /** Brand and sacco are both reached through the sacco relation. */
    protected ?string $brandVia = 'sacco';

    protected $fillable = [
        'sacco_id', 'route_id', 'from_place_id', 'to_place_id', 'amount', 'status',
        // NULL = the base fare, charged outside every peak window. Non-null =
        // this segment's price while that period is live.
        'fare_period_id',
    ];

    protected $casts = [
        'amount' => 'float',
        'status' => 'boolean',
    ];

    public function sacco()
    {
        return $this->belongsTo(Sacco::class);
    }

    public function route()
    {
        return $this->belongsTo(Route::class);
    }

    public function farePeriod()
    {
        return $this->belongsTo(FarePeriod::class);
    }

    public function fromPlace()
    {
        return $this->belongsTo(Place::class, 'from_place_id');
    }

    public function toPlace()
    {
        return $this->belongsTo(Place::class, 'to_place_id');
    }
}
