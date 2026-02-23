<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AttendanceSetting extends Model
{
    protected $fillable = ['start_time', 'late_threshold', 'end_time', 'work_days'];
}
