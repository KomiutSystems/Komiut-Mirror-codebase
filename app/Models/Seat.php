<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Seat extends Model
{
    use HasFactory;
    protected $fillable = ["name", "seats", "rows", "columns", "status"];

    public function seat_arrangements(){
        return $this->hasMany(SeatArrangement::class);
    }
}
