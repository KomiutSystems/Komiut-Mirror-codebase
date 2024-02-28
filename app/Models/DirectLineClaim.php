<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class DirectLineClaim extends Model
{
    use HasFactory, SoftDeletes;
    protected $fillable = ["vehicle_id","passenger_name","passenger_phone","travel_date",
    "source", "status", "claim_response", "transaction_id"];

    public function vehicle(){
        return $this->belongsTo(Vehicle::class);
    }

    public function transaction(){
        return $this->belongsTo(Transaction::class);
    }
}
