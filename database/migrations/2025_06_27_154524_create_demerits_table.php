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
        Schema::create('demerits', function (Blueprint $table) {
            $table->id();
            $table->timestamps();
            $table->datetime('synced_at')->nullable();
            $table->bigInteger('record_id')->unsigned()->unique();
            $table->bigInteger('student_id');
            $table->bigInteger('teacher_id');
            $table->bigInteger('class_id');
            $table->string('subject')->nullable();
            $table->string('category');
            $table->string('reason')->nullable();
            $table->dateTime('date');
            $table->integer('points');
            $table->string('comment')->nullable();
            $table->string('arbor_point_id')->nullable();
            $table->string('arbor_behaviour_id')->nullable();
            $table->string('arbor_behaviour_student_id')->nullable();
            $table->json('arbor_action_id')->nullable();
            $table->string('arbor_detention_id')->nullable();
            $table->integer('sync_status')->default(1);
            $table->boolean('matched_teacher')->default(false);
            $table->boolean('matched_class')->default(false);
        });
    }

    /**`
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('demerits');
    }
};
