<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\Concerns\BelongsToSacco;
use Illuminate\Database\Eloquent\Model;
use Spatie\Permission\Models\Role;

class PointSetting extends Model
{
    use HasFactory, BelongsToSacco;
    protected $fillable = ["amount","items","points_on","points_type","role_id", "sacco_id","start_date","completed","status"];

    public function sacco(){
        return $this->belongsTo(Sacco::class);
    }
    public function role(){
        return $this->belongsTo(Role::class);
    }
}
