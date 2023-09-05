<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Terminus extends Model
{
    use HasFactory;
    protected $fillable = ["name", "longitude", "latitude", 'place_id', 'status'];

    public function place(){
        return $this->belongsTo(Place::class);
    }
}
