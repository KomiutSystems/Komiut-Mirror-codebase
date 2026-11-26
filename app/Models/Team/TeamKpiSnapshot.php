<?php

declare(strict_types=1);

namespace App\Models\Team;

use Illuminate\Database\Eloquent\Model;

/** A level-type KPI's value as it stood at the end of a day. */
class TeamKpiSnapshot extends Model
{
    protected $fillable = ['key', 'date', 'value'];

    protected $casts = ['date' => 'date:Y-m-d', 'value' => 'float'];
}
