<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSacco;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\Concerns\BelongsToBrand;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Vehicle extends Model
{
    use HasFactory, BelongsToSacco, BelongsToBrand;
    protected $fillable = ["plate","fleet_no","till_number","merchant_short_code","sacco_id","user_id",'seat_id','mpesa_payment_setting_id','status','brand','financier'];

    public function sacco(){
        return $this->belongsTo(Sacco::class);
    }

    public function user(){
        return $this->belongsTo(User::class);
    }
    public function seat(){
        return $this->belongsTo(Seat::class);
    }
    public function vehicle_user(){
        return $this->hasMany(VehicleUser::class);
    }
    public function mpesa_payment_setting(){
        return $this->belongsTo(MpesaPaymentSetting::class);
    }
}
