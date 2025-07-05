<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class teacher_remarks extends Model
{
    protected $table = "teacher_remarks";
    public $timestamps = false;
    protected $primaryKey = 'id';

    // Define the fillable properties for mass assignment
    protected $fillable = [
        'student_id',
        'teacher_offered_course_id',
        'remarks'
    ];
    public function teacherOfferedCourse()
    {
        return $this->belongsTo(teacher_offered_courses::class, 'teacher_offered_course_id');
    }

    public function student()
    {
        return $this->belongsTo(Student::class, 'student_id', 'id');
    }
    public static function getRemarks($teacher_offered_course_id, $student_id)
    {

        $record = self::where('teacher_offered_course_id', $teacher_offered_course_id)
            ->where('student_id', $student_id)
            ->first();

        return $record?->remarks;
    }
    public static function addRemarks($teacher_offered_course_id, $student_id, $remarks)
    {
        return self::updateOrCreate(
            [
                'student_id' => $student_id,
                'teacher_offered_course_id' => $teacher_offered_course_id
            ],
            ['remarks' => $remarks]
        );
    }
}
