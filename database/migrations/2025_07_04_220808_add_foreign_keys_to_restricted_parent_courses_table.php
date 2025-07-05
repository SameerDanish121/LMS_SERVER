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
        Schema::table('restricted_parent_courses', function (Blueprint $table) {
            $table->foreign(['parent_id'], 'restricted_parent_courses_ibfk_1')->references(['id'])->on('parents')->onUpdate('no action')->onDelete('no action');
            $table->foreign(['student_id'], 'restricted_parent_courses_ibfk_2')->references(['id'])->on('student')->onUpdate('no action')->onDelete('no action');
            $table->foreign(['course_id'], 'restricted_parent_courses_ibfk_3')->references(['id'])->on('course')->onUpdate('no action')->onDelete('no action');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('restricted_parent_courses', function (Blueprint $table) {
            $table->dropForeign('restricted_parent_courses_ibfk_1');
            $table->dropForeign('restricted_parent_courses_ibfk_2');
            $table->dropForeign('restricted_parent_courses_ibfk_3');
        });
    }
};
