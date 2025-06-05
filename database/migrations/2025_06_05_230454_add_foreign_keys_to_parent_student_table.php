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
        Schema::table('parent_student', function (Blueprint $table) {
            $table->foreign(['parent_id'], 'parent_student_ibfk_1')->references(['id'])->on('parents')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['student_id'], 'parent_student_ibfk_2')->references(['id'])->on('student')->onUpdate('no action')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('parent_student', function (Blueprint $table) {
            $table->dropForeign('parent_student_ibfk_1');
            $table->dropForeign('parent_student_ibfk_2');
        });
    }
};
