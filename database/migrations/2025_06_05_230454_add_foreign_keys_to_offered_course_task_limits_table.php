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
        Schema::table('offered_course_task_limits', function (Blueprint $table) {
            $table->foreign(['offered_course_id'], 'fk_task_limit_offered_course')->references(['id'])->on('offered_courses')->onUpdate('no action')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('offered_course_task_limits', function (Blueprint $table) {
            $table->dropForeign('fk_task_limit_offered_course');
        });
    }
};
