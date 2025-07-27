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
        Schema::create('epraise_demerit_categories', function (Blueprint $table) {
            $table->id();
            $table->timestamps();
            $table->string('epraise_name')->unique();
            $table->foreignId('arbor_point_category_id')->nullable();
            $table->foreignId('arbor_behaviour_category_id')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('epraise_demerit_categories');
    }
};
