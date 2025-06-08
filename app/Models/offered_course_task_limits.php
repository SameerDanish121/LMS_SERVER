<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class offered_course_task_limits extends Model
{
  

    protected $table = 'offered_course_task_limits';

    protected $fillable = [
        'offered_course_id',
        'task_type',
        'task_limit',
    ];

    public $timestamps = false; 

   
    public function offeredCourse()
    {
        return $this->belongsTo(offered_courses::class, 'offered_course_id');
    }
}

