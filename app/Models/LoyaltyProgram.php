<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToBrand;
use App\Models\Concerns\BelongsToSacco;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One SACCO's loyalty terms.
 *
 *   divisor               KES of fare per point EARNED (100 = 1 point per KES 100).
 *   point_value           KES of fare one point PAYS FOR when redeemed. The
 *                         price of every ride in points follows from it:
 *                         (fare x seats) / point_value. Null means the SACCO has
 *                         not said what a point is worth, and nothing can be
 *                         redeemed until it does.
 *   redemption_threshold  Points for a STANDARD ride -- the goal on the
 *                         passenger's card ("20 more points to a free ride").
 *                         It describes; it does not price. It used to: a ride
 *                         cost this flat, whatever the fare and however many
 *                         seats, so one threshold bought any trip on the
 *                         network. See 2026_09_12_090000_give_a_loyalty_point_a_value.
 */
class LoyaltyProgram extends Model
{
    use HasFactory, BelongsToSacco, BelongsToBrand;

    protected ?string $brandVia = 'sacco';

    protected $fillable = ['sacco_id', 'divisor', 'redemption_threshold', 'point_value', 'is_active'];

    protected $casts = [
        'divisor' => 'float',
        'redemption_threshold' => 'float',
        'point_value' => 'float',
        'is_active' => 'boolean',
    ];

    public function sacco()
    {
        return $this->belongsTo(Sacco::class);
    }

    /** Whether this program can price a ride in points at all. */
    public function canPriceRides(): bool
    {
        return $this->point_value !== null && (float) $this->point_value > 0;
    }

    /**
     * What a fare costs in points, to two decimals -- the same precision the
     * ledger keeps for earning (KES 150 / divisor 100 = 1.5 points).
     *
     * Null when the program has no point value; the caller refuses rather than
     * guesses. Zero for a zero fare.
     */
    public function pointsFor(float $kes): ?float
    {
        if (! $this->canPriceRides()) {
            return null;
        }

        return round(max(0.0, $kes) / (float) $this->point_value, 2);
    }

    /** What a points balance would pay for, in KES of fare. Null when unpriced. */
    public function kesFor(float $points): ?float
    {
        if (! $this->canPriceRides()) {
            return null;
        }

        return round(max(0.0, $points) * (float) $this->point_value, 2);
    }
}
