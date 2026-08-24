<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBrand;
use App\Models\Concerns\BelongsToFinancier;
use App\Models\Concerns\BelongsToSacco;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Cash extends Model
{
    use HasFactory, BelongsToSacco, BelongsToBrand, BelongsToFinancier;

    /** Reaches the financed vehicle the same way $saccoVia does. */
    protected ?string $financierVia = 'vehicle';

    /** Reaches brand via vehicle. */
    protected ?string $brandVia = 'vehicle';

    /** Reaches sacco_id via the vehicle relation. */
    protected $saccoVia = 'vehicle';

    protected $fillable = ["trans_id","route_id",'from_id', 'to_id', "user_id", "vehicle_id", "firstname","lastname","recieved_amount",
    "fare_amount","luggage_amount","change_amount","total_amount","phone","passengers","trans_date"];

    public function vehicle(){
        return $this->belongsTo(Vehicle::class);
    }
}
