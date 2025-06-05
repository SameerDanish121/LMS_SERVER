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
        Schema::create('reenrollment_requests', function (Blueprint $table) {
            $table->integer('id', true);
            $table->integer('student_offered_course_id')->index('fk_reenroll_soc');
            $table->text('reason')->nullable();
            $table->enum('status', ['Pending', 'Accepted', 'Rejected'])->nullable()->default('Pending');
            $table->timestamp('requested_at')->nullable()->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reenrollment_requests');
    }
};
