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
        Schema::create('translate_points', function (Blueprint $table) {
            $table->id();
            $table->timestamps();
            $table->string('arbor_scale_id')->nullable();
            $table->string('arbor_scale_name')->nullable();
            $table->string('arbor_reason_id')->nullable();
            $table->string('arbor_reason_name')->nullable();
            $table->string('epraise_name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('translate_points');
    }
};
