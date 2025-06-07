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
        Schema::create('local_truefire_segments', function (Blueprint $table) {
            $table->mediumIncrements('id');
            $table->unsignedSmallInteger('channel_id')->index();
            $table->unsignedSmallInteger('sub_channel_id');
            $table->string('xmlchannel');
            $table->smallInteger('item_order');
            $table->string('name');
            $table->string('subhead');
            $table->string('video')->index();
            $table->string('preroll');
            $table->string('prerollgroup');
            $table->string('prerollchance');
            $table->string('postroll');
            $table->string('img1');
            $table->string('imgurl1');
            $table->string('img2');
            $table->string('imgurl2');
            $table->string('img3');
            $table->string('imgurl3');
            $table->string('adgroup');
            $table->string('adgroup_count', 10);
            $table->string('more');
            $table->string('thumbnail');
            $table->string('assettab');
            $table->string('asseturl');
            $table->string('free');
            $table->text('description');
            $table->string('cd');
            $table->string('tab');
            $table->string('jam');
            $table->string('pt');
            $table->text('extra_assets');
            $table->string('Level', 7);
            $table->string('Style', 30);
            $table->unsignedSmallInteger('runtime');
            $table->boolean('is_hd')->default(true);
            $table->string('document_checksum')->nullable();
            $table->datetime('document_date')->nullable();
            
            // Add Laravel timestamps
            $table->timestamps();
            
            // Foreign key constraint to local channels
            $table->foreign('channel_id')->references('id')->on('local_truefire_channels')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('local_truefire_segments');
    }
};
