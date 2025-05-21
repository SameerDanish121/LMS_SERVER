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
        Schema::create('grader_requests', function (Blueprint $table) {
            $table->integer('id', true);
            $table->integer('grader_id')->index('grader_id');
            $table->integer('teacher_id')->index('teacher_id');
            $table->enum('status', ['pending', 'accepted', 'rejected'])->nullable()->default('pending');
            $table->dateTime('requested_at')->nullable()->useCurrent();
            $table->dateTime('responded_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('grader_requests');
    }
};
