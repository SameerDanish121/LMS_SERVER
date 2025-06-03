<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class parents extends Model
{
    use HasFactory;

    // If your table name is not the plural of the model name,
    // specify it explicitly
    protected $table = 'parents';

    // Primary key type is int and auto-incrementing by default
    protected $primaryKey = 'id';

    // If you don't have timestamps columns, disable them
    public $timestamps = false;

    // Mass assignable attributes
    protected $fillable = [
        'user_id',
        'name',
        'relation_with_student',
        'contact',
        'address',
    ];

    /**
     * Parent belongs to a User
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Parent has many students (through pivot table parent_student)
     */
    public function students()
    {
        return $this->belongsToMany(Student::class, 'parent_student', 'parent_id', 'student_id');
    }
}
