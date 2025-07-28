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
        Schema::create('academic_calendar_dates', function (Blueprint $table) {
            $table->id();
            $table->timestamps();
            $table->bigInteger('arbor_id')->unsigned()->unique();
            $table->bigInteger('academic_year_id')->unsigned()->nullable();;
            $table->bigInteger('term_id')->unsigned()->nullable();;
            $table->dateTime('date');
            $table->integer('calendar_year');
            $table->integer('calendar_month');
            $table->integer('calendar_day');
            $table->integer('calendar_week');
            $table->integer('day_of_term')->nullable();;
            $table->integer('day_of_academic_year')->nullable();
            $table->boolean('is_school_day')->default(false);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('academic_calendar_dates');
    }
};
