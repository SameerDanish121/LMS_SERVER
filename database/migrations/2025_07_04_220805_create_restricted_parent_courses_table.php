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
        Schema::create('restricted_parent_courses', function (Blueprint $table) {
            $table->integer('id', true);
            $table->integer('parent_id');
            $table->integer('student_id')->index('student_id');
            $table->integer('course_id')->index('course_id');
            $table->enum('restriction_type', ['attendance', 'task', 'exam', 'core'])->nullable()->default('core');

            $table->unique(['parent_id', 'student_id', 'course_id'], 'parent_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('restricted_parent_courses');
    }
};
