<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class teacher extends Model
{
    // The table name is explicitly set to 'teacher'
    protected $table = 'teacher';
    protected $primaryKey = 'id';
    public $timestamps = false;
    protected $fillable = [
        'user_id', 
        'name', 
        'image', 
        'date_of_birth', 
        'gender',
        'cnic'
    ];
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
    public function timetables()
    {
        return $this->hasMany(Timetable::class, 'teacher_id', 'id');
    }
    public function getIDByName($Name = null)
    {
        if (!$Name) {
            return null;
        }
        $record = self::where('name', $Name)->select('id')->first();
        return $record ? $record->id : null;
    }
    
}
