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
        Schema::table('local_truefire_channels', function (Blueprint $table) {
            $table->datetime('date_modified')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('local_truefire_channels', function (Blueprint $table) {
            $table->datetime('date_modified')->nullable(false)->change();
        });
    }
};
