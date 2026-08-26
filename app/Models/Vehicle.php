<?php

namespace App\Models;

use App\Models\Concerns\BelongsToFinancier;
use App\Models\Concerns\BelongsToSacco;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\Concerns\BelongsToBrand;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Vehicle extends Model
{
    use HasFactory, BelongsToSacco, BelongsToBrand, BelongsToFinancier;

    /**
     * Readable by a caller with no SACCO of their own. See
     * BelongsToSacco::allowsCrossTenantBrowsing() for what this does and does
     * not permit — it exempts the TENANTLESS caller only; a user who has a
     * SACCO is still filtered to it.
     *
     * Which buses are running. The whole point of the passenger app is to find
     * a matatu, and it is not going to be one from a SACCO they are a member of.
     */
    protected bool $saccoCrossTenantBrowsing = true;

    /*
     * Owns `financier`, so no $financierVia: this is the table every other
     * financier-scoped model reaches through. A bank user sees only the
     * vehicles their own bank financed; everyone else is unaffected.
     */
    protected $fillable = ["plate","fleet_no","till_number","merchant_short_code","sacco_id","user_id",'seat_id','mpesa_payment_setting_id','status','brand','financier','ncba_till','coop_till'];

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
