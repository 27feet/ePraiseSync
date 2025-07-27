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
        Schema::create('check_demerits', function (Blueprint $table) {
            $table->id();
            $table->timestamps();
            $table->bigInteger('record_id')->unsigned()->unique();
            $table->dateTime('date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('check_demerits');
    }
};
