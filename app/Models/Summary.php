<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\Concerns\BelongsToBrand;
use App\Models\Concerns\BelongsToFinancier;
use App\Models\Concerns\BelongsToSacco;
use Illuminate\Database\Eloquent\Model;

class Summary extends Model
{
    use HasFactory, BelongsToSacco, BelongsToBrand, BelongsToFinancier;

    /** Reaches the financed vehicle the same way $saccoVia does. */
    protected ?string $financierVia = 'vehicle';

    /** Reaches brand via vehicle. */
    protected ?string $brandVia = 'vehicle';

    /** Reaches sacco_id via the vehicle relation. */
    protected $saccoVia = 'vehicle';
    protected $fillable = ['vehicle_id', 'mpesa_amount', 'cash_amount', 'mpesa_txn','cash_txn','expense_fee_amount','trans_date'];

    public function vehicle(){
        return $this->belongsTo(Vehicle::class);
    }
}
