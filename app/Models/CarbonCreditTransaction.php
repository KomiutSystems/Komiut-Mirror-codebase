<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CarbonCreditType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/** Append-only ledger behind CarbonCreditAccount. Never updated in place. */
class CarbonCreditTransaction extends Model
{
    use HasFactory;

    protected $fillable = ['user_id', 'credits', 'type', 'spend_cents', 'booking_id', 'description'];

    protected $casts = [
        'credits' => 'integer',
        'spend_cents' => 'integer',
        'type' => CarbonCreditType::class,
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function booking()
    {
        return $this->belongsTo(Booking::class);
    }
}
