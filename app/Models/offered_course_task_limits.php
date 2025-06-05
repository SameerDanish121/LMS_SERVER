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

    public $timestamps = false; // Since your migration doesn't include created_at/updated_at

   
    public function offeredCourse()
    {
        return $this->belongsTo(offered_courses::class, 'offered_course_id');
    }
}

