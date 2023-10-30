<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Transaction extends Model
{
    use HasFactory;
    protected $fillable = ["cash_id", 'mpesa_id', "vehicle_id", "amount","redeemed","trans_date"];

    public function cash(){
        return $this->belongsTo(Cash::class);
    }
    public function mpesa(){
        return $this->belongsTo(Mpesa::class);
    }
    public function vehicle(){
        return $this->belongsTo(Vehicle::class);
    }
}
