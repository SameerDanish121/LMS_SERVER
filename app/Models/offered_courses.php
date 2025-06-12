<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class offered_courses extends Model
{
    protected $table = 'offered_courses';

    // Disable timestamps if not present in the table (no created_at/updated_at columns)
    public $timestamps = false;

    // Specify the primary key if it's different from 'id' (but here it's 'id' by default)
    protected $primaryKey = 'id';
    protected $fillable = [
        'course_id',
        'session_id'
    ];

    // Define relationships with Course and Session models
    public function course()
    {
        return $this->belongsTo(Course::class, 'course_id', 'id');
    }
    public function session()
    {
        return $this->belongsTo(Session::class, 'session_id', 'id');
    }
    public function exams()
    {
        return $this->hasMany(exam::class, 'offered_course_id', 'id');
    }
    public function teacherOfferedCourses()
    {
        return $this->hasMany(teacher_offered_courses::class, 'offered_course_id');
    }
    public function offeredCourseTaskLimits()
    {
        return $this->hasMany(offered_course_task_limits::class, 'offered_course_id');
    }

    public function studentOfferedCourses()
    {
        return $this->hasMany(student_offered_courses::class, 'offered_course_id');
    }
}
