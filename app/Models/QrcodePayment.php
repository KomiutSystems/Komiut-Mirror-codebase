<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBrand;
use App\Models\Concerns\BelongsToFinancier;
use App\Models\Concerns\BelongsToSacco;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class QrcodePayment extends Model
{
    use HasFactory, BelongsToSacco, BelongsToBrand, BelongsToFinancier;

    /** Reaches the financed vehicle the same way $saccoVia does. */
    protected ?string $financierVia = 'vehicle';

    /** Reaches brand via vehicle. */
    protected ?string $brandVia = 'vehicle';

    /** Reaches sacco_id via the vehicle relation. */
    protected $saccoVia = 'vehicle';

    protected $fillable = ["vehicle_id","seat_arrangement_id","user_id","amount","status"];

    public function vehicle(){
        return $this->belongsTo(Vehicle::class);
    }
    public function seat_arrangement(){
        return $this->belongsTo(SeatArrangement::class);
    }
    public function user(){
        return $this->belongsTo(User::class);
    }

    public function mpesa_qrcode_payment(){
        return $this->hasOne(MpesaQrcodePayment::class);
    }
}
