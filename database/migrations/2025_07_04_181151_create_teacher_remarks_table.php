<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('teacher_remarks', function (Blueprint $table) {
            $table->integer('id', true);
            $table->integer('student_id')->index('student_id');
            $table->integer('teacher_offered_course_id')->index('teacher_offered_course_id');
            $table->text('remarks')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('teacher_remarks');
    }
};
