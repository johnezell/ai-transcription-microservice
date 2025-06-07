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
        Schema::create('local_truefire_channels', function (Blueprint $table) {
            $table->smallIncrements('id');
            $table->string('xml_filename')->unique();
            $table->string('title');
            $table->string('posterframe');
            $table->string('adimage');
            $table->string('adlink')->index();
            $table->enum('thumbnails', ['true', 'false'])->default('false');
            $table->string('guide');
            $table->string('emailredirect');
            $table->string('prerollchance');
            $table->string('prerollgroup');
            $table->string('postroll');
            $table->string('bannerform');
            $table->text('description');
            $table->string('menuImage');
            $table->string('video');
            $table->string('commercial', 244);
            $table->string('more');
            $table->string('adimage2');
            $table->string('adlink2');
            $table->string('foldername')->index();
            $table->string('version', 10);
            $table->string('style')->nullable(); // Store as comma-separated values
            $table->string('curriculum')->nullable(); // Store as comma-separated values
            $table->unsignedTinyInteger('level1')->nullable();
            $table->string('level')->nullable(); // Store as comma-separated values
            $table->string('inlinechance');
            $table->string('inlinegroup');
            $table->string('bandwidthHi', 10);
            $table->string('bandwidthMed', 10);
            $table->time('run_time')->default('00:00:00');
            $table->boolean('new_item');
            $table->boolean('on_sale');
            $table->boolean('top_picks');
            $table->unsignedSmallInteger('tf_itemid')->index();
            $table->string('tf_thumb');
            $table->unsignedSmallInteger('tf_authorid');
            $table->string('tf_thumb2');
            $table->string('educator_name');
            $table->string('educator_url');
            $table->string('video_prefix')->index();
            $table->unsignedSmallInteger('courseid')->index();
            $table->datetime('date_modified');
            $table->text('add_fields');
            $table->text('ch_extra_assets');
            
            // Add Laravel timestamps
            $table->timestamps();
            
            // Foreign key constraint to local courses
            $table->foreign('courseid')->references('id')->on('local_truefire_courses')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('local_truefire_channels');
    }
};
