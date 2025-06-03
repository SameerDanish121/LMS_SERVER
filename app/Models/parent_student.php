<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class parent_student extends Model
{
    use HasFactory;

    protected $table = 'parent_student';

    protected $primaryKey = 'id';

    public $timestamps = false;

    protected $fillable = [
        'parent_id',
        'student_id',
    ];
     public function parent()
    {
        return $this->belongsTo(parents::class, 'parent_id');
    }

    // Relation to Student
    public function student()
    {
        return $this->belongsTo(Student::class, 'student_id');
    }
}
