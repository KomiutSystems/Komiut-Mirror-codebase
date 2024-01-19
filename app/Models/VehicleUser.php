<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class VehicleUser extends Model
{
    use HasFactory, SoftDeletes;
    protected $fillable = ["user_id","vehicle_id","sacco_id", 'start_date','end_date', 'status'];

    public function user(){
        return $this->belongsTo(User::class);
    }
    public function vehicle(){
        return $this->belongsTo(Vehicle::class);
    }
    public function sacco(){
        return $this->belongsTo(Sacco::class);
    }
}
