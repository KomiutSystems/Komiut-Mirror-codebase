<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

class SaccoRoute extends Model
{
    use HasFactory;

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
