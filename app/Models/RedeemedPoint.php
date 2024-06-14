<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RedeemedPoint extends Model
{
    use HasFactory;
    protected $fillable = ["point_id","vehicle_id","points", 'from_id', 'to_id',"status"];

    public function from(){
        return $this->belongsTo(Place::class, 'from_id');
    }
    public function to(){
        return $this->belongsTo(Place::class, 'to_id');
    }
    public function point(){
        return $this->belongsTo(Point::class);
    }
    public function vehicle(){
        return $this->belongsTo(Vehicle::class);
    }
}
