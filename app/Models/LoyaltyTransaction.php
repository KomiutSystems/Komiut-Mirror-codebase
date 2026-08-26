<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\LoyaltyTransactionType;
use App\Models\Concerns\BelongsToBrand;
use App\Models\Concerns\BelongsToSacco;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LoyaltyTransaction extends Model
{
    use HasFactory, BelongsToSacco, BelongsToBrand;

    protected ?string $brandVia = 'sacco';

    protected $fillable = ['user_id', 'sacco_id', 'value', 'type', 'booking_id'];

    protected $casts = [
        'value' => 'float',
        'type' => LoyaltyTransactionType::class,
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function sacco()
    {
        return $this->belongsTo(Sacco::class);
    }

    public function booking()
    {
        return $this->belongsTo(Booking::class);
    }
}
