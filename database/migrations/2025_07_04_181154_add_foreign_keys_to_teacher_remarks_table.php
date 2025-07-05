<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.a
     */
    public function up(): void
    {
        Schema::table('teacher_remarks', function (Blueprint $table) {
            $table->foreign(['student_id'], 'teacher_remarks_ibfk_1')->references(['id'])->on('student')->onUpdate('no action')->onDelete('no action');
            $table->foreign(['teacher_offered_course_id'], 'teacher_remarks_ibfk_2')->references(['id'])->on('teacher_offered_courses')->onUpdate('no action')->onDelete('no action');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('teacher_remarks', function (Blueprint $table) {
            $table->dropForeign('teacher_remarks_ibfk_1');
            $table->dropForeign('teacher_remarks_ibfk_2');
        });
    }
};
