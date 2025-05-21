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
        Schema::table('grader_requests', function (Blueprint $table) {
            $table->foreign(['grader_id'], 'grader_requests_ibfk_1')->references(['id'])->on('grader')->onUpdate('cascade')->onDelete('cascade');
            $table->foreign(['teacher_id'], 'grader_requests_ibfk_2')->references(['id'])->on('teacher')->onUpdate('cascade')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('grader_requests', function (Blueprint $table) {
            $table->dropForeign('grader_requests_ibfk_1');
            $table->dropForeign('grader_requests_ibfk_2');
        });
    }
};
