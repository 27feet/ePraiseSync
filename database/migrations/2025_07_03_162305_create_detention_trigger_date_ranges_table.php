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
        Schema::create('detention_trigger_date_ranges', function (Blueprint $table) {
            $table->id();
            $table->timestamps();
            $table->string('range_name')->unique();
            $table->enum('range_type', ['days', 'weeks', 'term', 'year'])->default('year');
            $table->integer('trigger_value')->unsigned()->default(1);
            $table->boolean('active')->default(true);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('detention_trigger_date_ranges');
    }
};
