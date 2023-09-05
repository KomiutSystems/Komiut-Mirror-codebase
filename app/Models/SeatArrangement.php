<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SeatArrangement extends Model
{
    use HasFactory;
    protected $fillable = ["name", "seat_id", "row", "column", "status"];
}
