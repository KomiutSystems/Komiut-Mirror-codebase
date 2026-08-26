<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\Concerns\BelongsToSacco;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

class SaccoRoute extends Model
{
    use HasFactory, BelongsToSacco;

    /**
     * Readable by a caller with no SACCO of their own. See
     * BelongsToSacco::allowsCrossTenantBrowsing() for what this does and does
     * not permit — it exempts the TENANTLESS caller only; a user who has a
     * SACCO is still filtered to it.
     *
     * Where the buses go. Route catalogue, shown before any booking exists.
     */
    protected bool $saccoCrossTenantBrowsing = true;

    protected $fillable = ['user_id', 'route_id', 'sacco_id', 'amount', 'min_amount', 'status'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function route()
    {
        return $this->belongsTo(Route::class);
    }

    public function sacco()
    {
        return $this->belongsTo(Sacco::class);
    }
}
