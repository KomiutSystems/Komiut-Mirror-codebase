<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RewardPartner;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/** Something credits can be exchanged for. Platform-owned catalogue. */
class CarbonCreditReward extends Model
{
    use HasFactory;

    protected $fillable = [
        'name', 'partner', 'description', 'credits_required', 'sacco_id', 'stock', 'is_active',
    ];

    protected $casts = [
        'partner' => RewardPartner::class,
        'credits_required' => 'integer',
        'stock' => 'integer',
        'is_active' => 'boolean',
    ];

    public function sacco()
    {
        return $this->belongsTo(Sacco::class);
    }

    public function redemptions()
    {
        return $this->hasMany(CarbonCreditRedemption::class);
    }

    /** Null stock is unlimited; zero is sold out. */
    public function isClaimable(): bool
    {
        return $this->is_active && ($this->stock === null || $this->stock > 0);
    }
}
