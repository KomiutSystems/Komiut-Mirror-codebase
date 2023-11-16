<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Summary extends Model
{
    use HasFactory;
    protected $fillable = ['vehicle_id', 'mpesa_amount', 'cash_amount', 'mpesa_txn','cash_txn','trans_date'];

    public function vehicle(){
        return $this->belongsTo(Vehicle::class);
    }
}
