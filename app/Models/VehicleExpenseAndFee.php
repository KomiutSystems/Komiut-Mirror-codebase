<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBrand;
use App\Models\Concerns\BelongsToSacco;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class VehicleExpenseAndFee extends Model
{
    use HasFactory, BelongsToSacco, BelongsToBrand;

    /**
     * Reaches its tenant through the vehicle — the same shape Summary,
     * Transaction, Queue and Parcel already use.
     *
     * This model carried no tenancy trait at all, and the listing endpoint's only
     * narrowing was the caller's own vehicle assignments, applied ONLY when they
     * had any. An office admin has none, so the filter was skipped and the
     * listing ran unconstrained: verified live, a NICCO admin was reading expense
     * rows for vehicles belonging to Marafiki (sacco 42) and Fins Travel
     * (sacco 45). The write path was worse — findOrFail() would load any expense
     * row on the platform by id.
     */
    protected $saccoVia = 'vehicle';

    /** Same path, same reason. */
    protected ?string $brandVia = 'vehicle';

    protected $fillable = ["vehicle_id","expense_fee_id","amount","trans_date","status"];

    public function expense_fee(){
        return $this->belongsTo(ExpenseFee::class);
    }

    public function vehicle(){
        return $this->belongsTo(Vehicle::class);
    }
}
