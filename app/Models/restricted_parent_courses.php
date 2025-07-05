<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class restricted_parent_courses extends Model
{

    // Specify the table name
    protected $table = 'restricted_parent_courses';

    // Disable timestamps (since your table doesn’t have created_at/updated_at)
    public $timestamps = false;

    // Define fillable columns
    protected $fillable = [
        'parent_id',
        'student_id',
        'course_id',
        'restriction_type'
    ];

    // Parent relationship
    public function parent()
    {
        return $this->belongsTo(Parents::class, 'parent_id');
    }

    // Student relationship
    public function student()
    {
        return $this->belongsTo(Student::class, 'student_id');
    }

    // Course relationship
    public function course()
    {
        return $this->belongsTo(Course::class, 'course_id');
    }
    public static function insertOne(int $parentId, int $studentId, int $courseId, string $restrictionType = 'core'): ?self
    {
        $exists = self::where('parent_id', $parentId)
            ->where('student_id', $studentId)
            ->where('course_id', $courseId)
            ->where('restriction_type', $restrictionType)
            ->exists();


        return self::updateOrCreate([
            'parent_id' => $parentId,
            'student_id' => $studentId,
            'course_id' => $courseId,
            'restriction_type' => $restrictionType,
        ]);
    }
    public static function deleteById(int $id)
    {
        return self::where('id', $id)->delete();
    }
}
