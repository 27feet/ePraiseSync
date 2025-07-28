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
        Schema::create('detention_sessions', function (Blueprint $table) {
            $table->id();
            $table->timestamps();
            $table->integer('detention_type_id')->unsigned();
            $table->bigInteger('arbor_id')->unsigned()->unique();
            $table->dateTime('start_date_time');
            $table->dateTime('end_date_time');
            $table->float('durationMinutes')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('detention_sessions');
    }
};
