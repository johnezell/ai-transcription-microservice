<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Creates the local TrueFire authors table for instructor information.
     */
    public function up(): void
    {
        Schema::create('local_truefire_authors', function (Blueprint $table) {
            $table->id(); // Laravel primary key
            $table->integer('authorid')->unique()->index(); // TrueFire author ID - matches courses.authorid
            $table->string('authorfirstname')->nullable();
            $table->string('authorlastname')->nullable();
            
            // Laravel timestamps
            $table->timestamps();
            
            // Indexes for performance
            $table->index(['authorfirstname', 'authorlastname']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('local_truefire_authors');
    }
};
