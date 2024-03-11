<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class VehicleExpenseAndFee extends Model
{
    use HasFactory;
    protected $fillable = ["vehicle_id","expense_fee_id","amount","trans_date","status"];

    public function expense_fee(){
        return $this->belongsTo(ExpenseFee::class);
    }

    public function vehicle(){
        return $this->belongsTo(Vehicle::class);
    }
}
