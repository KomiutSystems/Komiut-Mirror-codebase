<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSacco;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A road a SACCO's matatus ply, from first stage to last.
 *
 * OWNED, as of 2026-08-26. This was a global unscoped catalogue on the theory
 * that a corridor is a physical fact several SACCOs share. In practice nothing
 * was ever shared — 1,971 of 1,972 production rows had no SACCO pointing at
 * them and not one route was referenced by two SACCOs — while the global table
 * made routes/add, routes/stages/add and routes/stages/coords/add into
 * cross-tenant writes: an `id` plus a permission check, no ownership test, so
 * any SACCO Admin could re-destination another SACCO's route and silently take
 * its fares and live queues with it.
 *
 * Two SACCOs running Nairobi–Thika now hold a route row each, priced and staged
 * independently. That is what a passenger is choosing between anyway: not a
 * corridor, but "whose bus, leaving when, for how much".
 */
class Route extends Model
{
    use HasFactory, BelongsToSacco;

    protected $fillable = ['sacco_id', 'name', 'from_id', 'to_id', 'status'];

    /**
     * Readable by a caller with no SACCO of their own. See
     * BelongsToSacco::allowsCrossTenantBrowsing() — it exempts the TENANTLESS
     * caller only; a user who has a SACCO is still filtered to it.
     *
     * Catalogue. A passenger picks a route before they belong to anything, and
     * the route they want belongs to a SACCO they will never be a member of.
     * Closed, book_a_ride/routes would return nothing to every passenger on the
     * platform.
     */
    protected bool $saccoCrossTenantBrowsing = true;

    public function sacco(): BelongsTo
    {
        return $this->belongsTo(Sacco::class);
    }

    public function from()
    {
        return $this->belongsTo(Place::class, 'from_id');
    }

    public function to()
    {
        return $this->belongsTo(Place::class, 'to_id');
    }

    public function route_stages()
    {
        return $this->hasMany(RouteStage::class)->orderBy('distance', 'ASC');
    }

    public function queues()
    {
        return $this->hasMany(Queue::class);
    }
}
