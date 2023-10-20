<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Cash extends Model
{
    use HasFactory;
    protected $fillable = ["trans_id","route_id",'from_id', 'to_id', "user_id", "vehicle_id", "firstname","lastname","recieved_amount",
    "fare_amount","luggage_amount","change_amount","total_amount","phone","passengers","trans_date"];

    public function vehicle(){
        return $this->belongsTo(Vehicle::class);
    }
}
