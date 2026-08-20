<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToBrand;
use App\Models\Concerns\BelongsToSacco;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A driver's end-of-shift cash declaration for a vehicle on a business day.
 *
 * Tenant-scoped exactly like Queue/Transaction: it carries no sacco_id or brand
 * column of its own and reaches both through its vehicle, so a declaration
 * inherits the SACCO/brand of the bus it belongs to and the global scopes keep
 * one SACCO from ever reading another's counts.
 */
class CashSubmission extends Model
{
    use BelongsToBrand, BelongsToSacco, HasFactory;

    /** Reaches brand via the vehicle relation. */
    protected ?string $brandVia = 'vehicle';

    /** Reaches sacco_id via the vehicle relation. */
    protected $saccoVia = 'vehicle';

    protected $fillable = [
        'vehicle_id',
        'user_id',
        'business_date',
        'declared_amount',
        'note',
    ];

    protected $casts = [
        'declared_amount' => 'decimal:2',
        'business_date' => 'date',
    ];

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
