<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToBrand;
use App\Models\Concerns\BelongsToSacco;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LoyaltyAccount extends Model
{
    use HasFactory, BelongsToSacco, BelongsToBrand;

    protected ?string $brandVia = 'sacco';

    protected $fillable = ['user_id', 'sacco_id', 'balance'];

    protected $casts = [
        'balance' => 'float',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function sacco()
    {
        return $this->belongsTo(Sacco::class);
    }
}
