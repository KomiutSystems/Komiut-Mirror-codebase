<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\Concerns\BelongsToBrand;
use App\Models\Concerns\BelongsToFinancier;
use App\Models\Concerns\BelongsToSacco;
use Illuminate\Database\Eloquent\Model;

class Transaction extends Model
{
    use HasFactory, BelongsToSacco, BelongsToBrand, BelongsToFinancier;

    /** Reaches the financed vehicle the same way $saccoVia does. */
    protected ?string $financierVia = 'vehicle';

    /** Reaches brand via vehicle. */
    protected ?string $brandVia = 'vehicle';

    /** Reaches sacco_id via the vehicle relation. */
    protected $saccoVia = 'vehicle';
    protected $fillable = ["cash_id", 'mpesa_id', "vehicle_id", "amount","redeemed","summarized", "points", "trans_date"];

    public function cash(){
        return $this->belongsTo(Cash::class);
    }
    public function mpesa(){
        return $this->belongsTo(Mpesa::class);
    }
    public function vehicle(){
        return $this->belongsTo(Vehicle::class);
    }
    public function direct_line_claim(){
        return $this->hasOne(DirectLineClaim::class);
    }
}
