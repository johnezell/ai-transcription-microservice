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
        Schema::table('truefire_segment_processing', function (Blueprint $table) {
            // Review status: 'approved', 'needs_revision', 'rejected', null (not reviewed)
            $table->string('review_status')->nullable()->after('completed_at');
            
            // Review feedback/comments from staff
            $table->text('review_feedback')->nullable()->after('review_status');
            
            // Who reviewed it
            $table->string('reviewed_by')->nullable()->after('review_feedback');
            
            // When it was reviewed
            $table->timestamp('reviewed_at')->nullable()->after('reviewed_by');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('truefire_segment_processing', function (Blueprint $table) {
            $table->dropColumn(['review_status', 'review_feedback', 'reviewed_by', 'reviewed_at']);
        });
    }
};
