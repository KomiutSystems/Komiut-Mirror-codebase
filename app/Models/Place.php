<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Place extends Model
{
    use HasFactory;
    protected $fillable = ["name","county_name", "longitude", "latitude","status"];

    public function from_parcels(){
        return $this->hasMany(Parcel::class, 'from_id');
    }
    public function to_parcels(){
        return $this->hasMany(Parcel::class, 'to_id');
    }

}
