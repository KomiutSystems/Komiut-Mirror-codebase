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

    /**
     * Readable by a caller with no SACCO of their own. See
     * BelongsToSacco::allowsCrossTenantBrowsing() — it exempts the TENANTLESS
     * caller only; a user who has a SACCO is still filtered to it.
     *
     * The passenger's OWN receipts — and only those. Like Booking, the boundary
     * here is identity, not tenancy: QrcodePaymentsController narrows a saccoless
     * caller to `user_id = auth()->id()` and will not let them widen it by
     * naming a SACCO. Closed, a passenger could not see the payment they had
     * just made.
     */
    protected bool $saccoCrossTenantBrowsing = true;

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
