<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class RouteStage extends Model
{
    use HasFactory;

    protected $fillable = ['route_id', 'place_id', 'longitude', 'latitude', 'distance','status'];

    public function place()
    {
        return $this->belongsTo(Place::class);

    }

    public function route()
    {
        return $this->belongsTo(Route::class);

    }
}
