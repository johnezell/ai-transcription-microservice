<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Creates an exact copy of the TrueFire courses table structure in our local database.
     */
    public function up(): void
    {
        Schema::create('local_truefire_courses', function (Blueprint $table) {
            // Exact copy of TrueFire courses table structure
            $table->id(); // Primary key
            $table->string('audio_extraction_preset')->nullable();
            $table->string('title')->nullable();
            $table->text('bigpageurl')->nullable();
            $table->string('version')->nullable();
            $table->date('version_date')->nullable();
            $table->string('checksum')->nullable();
            $table->string('path')->nullable();
            $table->text('changelog')->nullable();
            $table->boolean('allow_streaming')->default(false);
            $table->string('status')->nullable();
            $table->boolean('allow_firesale')->default(false);
            $table->date('new_till')->nullable();
            $table->string('staff_pic')->nullable();
            $table->boolean('is_free')->default(false);
            $table->longText('page_html')->nullable();
            $table->integer('authorid')->nullable();
            $table->boolean('mp4_ready')->default(false);
            $table->date('release_date')->nullable();
            $table->text('long_description')->nullable();
            $table->text('short_description')->nullable();
            $table->string('page_title')->nullable();
            $table->string('perma_link')->nullable();
            $table->string('author_url')->nullable();
            $table->string('moov')->nullable();
            $table->string('youtube_intro_link')->nullable();
            $table->string('meta_title')->nullable();
            $table->text('meta_description')->nullable();
            $table->bigInteger('course_size')->nullable();
            $table->string('fb_like_url')->nullable();
            $table->string('fb_share_url')->nullable();
            $table->string('fb_comments_url')->nullable();
            $table->string('segments_checksum')->nullable();
            $table->text('additional_authors')->nullable();
            $table->integer('free_remaining')->nullable();
            $table->integer('video_count')->nullable();
            $table->string('suppl_cids')->nullable();
            $table->boolean('is_compilation')->default(false);
            $table->text('workshop_study_guide')->nullable();
            $table->boolean('is_foundry')->default(false);
            $table->string('soundslice_checksum')->nullable();
            $table->boolean('is_hd')->default(false);
            $table->boolean('is_camp')->default(false);
            $table->bigInteger('course_size_hd')->nullable();
            $table->longText('sandbox_html')->nullable();
            $table->boolean('is_playstore')->default(false);
            $table->json('meta')->nullable();
            $table->date('early_access_date')->nullable();
            $table->decimal('artist_per_view_royalty', 10, 4)->nullable();
            $table->boolean('jp_course')->default(false);
            $table->string('class')->nullable();
            $table->boolean('featured')->default(false);
            $table->string('persona')->nullable();
            $table->json('ios_data')->nullable();
            $table->boolean('aligned_with_artist')->default(false);
            $table->string('studio')->nullable();
            $table->date('document_date')->nullable();
            $table->string('document_checksum')->nullable();
            $table->timestamp('last_updated')->nullable();
            
            // Laravel timestamps
            $table->timestamps();
            
            // Indexes for performance
            $table->index('title');
            $table->index('status');
            $table->index('release_date');
            $table->index('authorid');
            $table->index('is_free');
            $table->index('featured');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('local_truefire_courses');
    }
};
