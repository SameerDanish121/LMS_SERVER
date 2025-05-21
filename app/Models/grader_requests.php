<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class grader_requests extends Model
{
    protected $table = 'grader_requests';

    public $timestamps = false;

    protected $fillable = [
        'grader_id',
        'teacher_id',
        'status',
        'requested_at',
        'responded_at',
    ];

    /**
     * The grader this request is for.
     */
    public function grader()
    {
        return $this->belongsTo(Grader::class, 'grader_id');
    }

    /**
     * The teacher who made the request.
     */
    public function teacher()
    {
        return $this->belongsTo(Teacher::class, 'teacher_id');
    }
}

