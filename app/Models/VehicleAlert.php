<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A bus went quiet during its shift, or took nothing by noon. */
class VehicleAlert extends Model
{
    use HasFactory;

    public const OFFLINE = 'offline_mid_shift';

    public const NO_COLLECTIONS = 'no_collections_by_noon';

    protected $fillable = ['owner_id', 'vehicle_id', 'type', 'details', 'raised_at', 'resolved_at'];

    protected $casts = [
        'details' => 'array',
        'raised_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    public function publicId(): string
    {
        return 'al_'.$this->id;
    }

    /** `al_1042` and a bare `1042` both resolve. */
    public static function parseId(string $given): ?int
    {
        if (preg_match('/^(?:al_)?(\d+)$/i', trim($given), $m) !== 1) {
            return null;
        }

        return (int) $m[1];
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }
}
