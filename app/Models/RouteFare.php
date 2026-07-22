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

    /** Brand and sacco are both reached through the sacco relation. */
    protected ?string $brandVia = 'sacco';
    protected $saccoVia = 'sacco';

    protected $fillable = [
        'sacco_id', 'route_id', 'from_place_id', 'to_place_id', 'amount', 'status',
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

    public function fromPlace()
    {
        return $this->belongsTo(Place::class, 'from_place_id');
    }

    public function toPlace()
    {
        return $this->belongsTo(Place::class, 'to_place_id');
    }
}
