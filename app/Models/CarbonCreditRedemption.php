<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RedemptionStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/** One claim against the catalogue. Credits are already debited at `pending`. */
class CarbonCreditRedemption extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id', 'carbon_credit_reward_id', 'credits_spent', 'status', 'reference', 'fulfilled_at',
    ];

    protected $casts = [
        'credits_spent' => 'integer',
        'status' => RedemptionStatus::class,
        'fulfilled_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function reward()
    {
        return $this->belongsTo(CarbonCreditReward::class, 'carbon_credit_reward_id');
    }
}
