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
        Schema::create('offered_course_task_limits', function (Blueprint $table) {
            $table->integer('id', true);
            $table->integer('offered_course_id')->index('fk_task_limit_offered_course');
            $table->enum('task_type', ['Quiz', 'Assignment', 'LabTask']);
            $table->integer('task_limit')->default(0);

            $table->unique(['offered_course_id', 'task_type'], 'unique_task_limit');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('offered_course_task_limits');
    }
};
