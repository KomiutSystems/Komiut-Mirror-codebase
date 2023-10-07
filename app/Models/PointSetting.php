<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\Permission\Models\Role;

class PointSetting extends Model
{
    use HasFactory;
    protected $fillable = ["amount","items","points_on","points_type","role_id", "sacco_id","status"];

    public function sacco(){
        return $this->belongsTo(Sacco::class);
    }
    public function role(){
        return $this->belongsTo(Role::class);
    }
}
