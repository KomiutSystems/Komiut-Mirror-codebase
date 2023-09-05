<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Parcel extends Model
{
    use HasFactory;
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
