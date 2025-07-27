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
        Schema::create('arbor_classes', function (Blueprint $table) {
            $table->id();
            $table->timestamps();
            $table->bigInteger('arbor_id')->unsigned()->unique();
            $table->string('name');
            $table->dateTime('start_date_time')->nullable();
            $table->dateTime('end_date_time')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('arbor_classes');
    }
};
