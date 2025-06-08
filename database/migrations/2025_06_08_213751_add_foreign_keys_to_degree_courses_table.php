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
        Schema::table('degree_courses', function (Blueprint $table) {
            $table->foreign(['course_id'], 'degree_courses_ibfk_1')->references(['id'])->on('course')->onUpdate('no action')->onDelete('no action');
            $table->foreign(['program_id'], 'degree_courses_ibfk_2')->references(['id'])->on('program')->onUpdate('no action')->onDelete('no action');
            $table->foreign(['session_id'], 'degree_courses_ibfk_3')->references(['id'])->on('session')->onUpdate('no action')->onDelete('no action');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('degree_courses', function (Blueprint $table) {
            $table->dropForeign('degree_courses_ibfk_1');
            $table->dropForeign('degree_courses_ibfk_2');
            $table->dropForeign('degree_courses_ibfk_3');
        });
    }
};
