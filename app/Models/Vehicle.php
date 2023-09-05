<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Vehicle extends Model
{
    use HasFactory;
    protected $fillable = ["plate","fleet_no","till_number","merchant_short_code","sacco_id","user_id",'seat_id','status'];

    public function sacco(){
        return $this->belongsTo(Sacco::class);
    }

    public function user(){
        return $this->belongsTo(User::class);
    }
    public function seat(){
        return $this->belongsTo(Seat::class);
    }
}
