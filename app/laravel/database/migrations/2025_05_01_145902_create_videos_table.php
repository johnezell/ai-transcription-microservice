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
        Schema::create('videos', function (Blueprint $table) {
            $table->id();
            $table->string('original_filename');
            $table->string('storage_path');
            $table->string('s3_key')->nullable()->comment('S3 object key (simulated locally)');
            $table->string('mime_type');
            $table->bigInteger('size_bytes');
            $table->string('status')->default('uploaded')->comment('uploaded, processing, processed, failed');
            $table->text('metadata')->nullable()->comment('JSON metadata');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('videos');
    }
};
