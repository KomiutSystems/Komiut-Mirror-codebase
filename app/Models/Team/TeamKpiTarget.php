<?php

declare(strict_types=1);

namespace App\Models\Team;

use Illuminate\Database\Eloquent\Model;

/** The team's target for one KPI over one kind of period (week or month). */
class TeamKpiTarget extends Model
{
    protected $fillable = ['key', 'period', 'target', 'updated_by'];

    protected $casts = ['target' => 'float', 'updated_by' => 'integer'];
}
