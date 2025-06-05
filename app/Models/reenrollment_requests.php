<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class reenrollment_requests extends Model
{
   
    protected $table = 'reenrollment_requests';

    public $timestamps = false; // Since there's only a single timestamp: requested_at

    protected $fillable = [
        'student_offered_course_id',
        'reason',
        'status',
        'requested_at',
    ];

    protected $casts = [
        'requested_at' => 'datetime',
    ];

    /**
     * Relationship: Each reenrollment request belongs to a student-offered course.
     */
    public function studentOfferedCourse()
    {
        return $this->belongsTo(student_offered_courses::class, 'student_offered_course_id');
    }


}
