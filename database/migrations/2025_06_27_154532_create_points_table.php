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
        Schema::create('points', function (Blueprint $table) {
            $table->id();
            $table->timestamps();
            $table->datetime('synced_at')->nullable();
            $table->bigInteger('record_id')->unsigned()->unique();
            $table->bigInteger('student_id');
            $table->bigInteger('teacher_id');
            $table->bigInteger('class_id');
            $table->string('subject')->nullable();
            $table->string('category');
            $table->dateTime('date');
            $table->integer('points');
            $table->string('arbor_id')->nullable();
            $table->boolean('matched_teacher')->default(false);
            $table->boolean('matched_class')->default(false);
            $table->integer('sync_status')->default(1);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('points');
    }
};
