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
        Schema::table('videos', function (Blueprint $table) {
            $table->uuid('course_id')->nullable()->after('id');
            $table->foreign('course_id')->references('id')->on('courses')->onDelete('set null');
            $table->integer('lesson_number')->nullable()->after('course_id')->comment('Ordering number within the course');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('videos', function (Blueprint $table) {
            $table->dropForeign(['course_id']);
            $table->dropColumn('course_id');
            $table->dropColumn('lesson_number');
        });
    }
}; 