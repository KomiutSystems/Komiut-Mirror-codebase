<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\Concerns\BelongsToSacco;
use Illuminate\Database\Eloquent\Model;

/**
 * Which termini a SACCO operates out of. Written only by
 * App\Http\Controllers\APIs\Super\Termini\SaccoTerminiController (attach/detach)
 * and by IndexApiController's legacy copy path.
 *
 * `status` stays OUT of $fillable on purpose. It defaults true in the database
 * and NO reader of this table filters on it — TerminusAPIController's SACCO
 * list, QueuesAPIController::getGeofence and the super console's brand filter
 * all ignore it — so exposing it would be a deactivate control that leaves the
 * link visible everywhere. Unlinking is a DELETE of the row instead, which is
 * the one "detached" state all three readers agree on. `user_id` is the operator
 * who created the link, matching the sibling sacco_routes pivot.
 */
class SaccoTerminus extends Model
{
    use HasFactory, BelongsToSacco;
    protected $fillable = ["terminus_id","sacco_id","user_id", "geofence_radius"];
    public function user() {
        return $this->belongsTo(User::class);
    }
    public function terminus(){
        return $this->belongsTo(Terminus::class);
    }
    public function sacco(){
        return $this->belongsTo(Sacco::class);
    }
}
