<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\Concerns\BelongsToSacco;
use Illuminate\Database\Eloquent\Model;

class Parcel extends Model
{
    use HasFactory, BelongsToSacco;

    /** Reaches sacco_id via the vehicle relation. */
    protected $saccoVia = 'vehicle';
    protected $fillable = ["recipient_name","recipient_phone","recipient_idno","sender_name","sender_phone",
    "sender_idno","name","description",'from_id', 'to_id', 'sender_id', 'recipient_id', 'created_by',
    "vehicle_id", "amount","arrival_time","picking_time","status", "paid"];

    public function creator(){
        return $this->belongsTo(User::class, 'created_by');
    }
    public function from(){
        return $this->belongsTo(Place::class, 'from_id');
    }
    public function to(){
        return $this->belongsTo(Place::class, 'to_id');
    }
    public function vehicle(){
        return $this->belongsTo(Vehicle::class);
    }
}
