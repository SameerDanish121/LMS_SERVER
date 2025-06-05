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
        Schema::table('juniorlecturer', function (Blueprint $table) {
            $table->foreign(['user_id'], 'juniorlecturer_ibfk_1')->references(['id'])->on('user')->onUpdate('no action')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('juniorlecturer', function (Blueprint $table) {
            $table->dropForeign('juniorlecturer_ibfk_1');
        });
    }
};
