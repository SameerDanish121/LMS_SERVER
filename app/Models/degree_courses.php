<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class degree_courses extends Model
{
    protected $table = 'degree_courses'; 
    public $timestamps = false;
    protected $primaryKey = 'id';
    protected $fillable = [
        'semester',
        'course_id',
        'program_id',
        'session_id'
    ];
    public function program()
    {
        return $this->belongsTo(program::class, 'program_id', 'id');
    }
    public function course()
    {
        return $this->belongsTo(Course::class, 'course_id', 'id');
    }
    public function session()
    {
        return $this->belongsTo(session::class, 'session_id', 'id');
    }
}
