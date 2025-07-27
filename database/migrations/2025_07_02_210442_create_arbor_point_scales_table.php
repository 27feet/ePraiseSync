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
        Schema::create('arbor_point_scales', function (Blueprint $table) {
            $table->id();
            $table->timestamps();
            $table->bigInteger('arbor_id')->nullable();
            $table->string('arbor_name')->nullable();
            $table->string('arbor_display_name')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('arbor_point_scales');
    }
};
