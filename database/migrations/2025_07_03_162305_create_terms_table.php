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
        Schema::create('terms', function (Blueprint $table) {
            $table->id();
            $table->timestamps();
            $table->bigInteger('arbor_id')->unsigned()->unique();
            $table->string('term_name');
            $table->bigInteger('academic_year_id')->unsigned();
            $table->dateTime('start_date');
            $table->dateTime('end_date');
            $table->dateTime('first_school_day_date')->nullable();
            $table->dateTime('last_school_day_date')->nullable();
            $table->bigInteger('first_school_day_id')->unsigned();
            $table->bigInteger('last_school_day_id')->unsigned();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('terms');
    }
};
