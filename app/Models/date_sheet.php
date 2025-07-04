<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class date_sheet extends Model
{
    protected $table = 'date_sheet';

    protected $primaryKey = 'id';

    // Specify that the primary key is not auto-incrementing (since it's an integer)
    public $incrementing = true;

    // Set the data type of the primary key
    protected $keyType = 'integer';

    // Disable timestamps (if the table does not have `created_at` and `updated_at` columns)
    public $timestamps = false;

    // Mass assignable attributes
    protected $fillable = [
        'Day',
        'Date',
        'Start_Time',
        'End_Time',
        'Type',
        'section_id',
        'course_id',
        'session_id',
    ];
    public function section()
    {
        return $this->belongsTo(Section::class, 'section_id');
    }

  
    public function course()
    {
        return $this->belongsTo(Course::class, 'course_id');
    }

    public function session()
    {
        return $this->belongsTo(Session::class, 'session_id');
    }
}
