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
        Schema::create('arbor_point_categories', function (Blueprint $table) {
            $table->id();
            $table->timestamps();
            $table->bigInteger('arbor_id')->nullable();
            $table->string('arbor_display_name')->nullable();
            $table->foreignId('arbor_point_scale_id')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('arbor_point_categories');
    }
};
