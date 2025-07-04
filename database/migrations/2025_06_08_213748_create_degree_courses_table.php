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
        Schema::create('degree_courses', function (Blueprint $table) {
            $table->integer('id', true);
            $table->integer('semester');
            $table->integer('course_id')->index('course_id');
            $table->integer('program_id')->index('program_id');
            $table->integer('session_id')->index('session_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('degree_courses');
    }
};
