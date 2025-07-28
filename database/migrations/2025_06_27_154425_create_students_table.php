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
        Schema::create('students', function (Blueprint $table) {
            $table->id();
            $table->timestamps();
            $table->bigInteger('record_id')->unsigned()->unique();
            $table->bigInteger('arbor_id')->unsigned()->unique();
            $table->string('name')->nullable();
            $table->string('year_group')->nullable();
            $table->json('year_leader_emails')->nullable();
            $table->json('tutor_emails')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('students');
    }
};
