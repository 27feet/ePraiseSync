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
        Schema::create('detention_triggers', function (Blueprint $table) {
            $table->id();
            $table->timestamps();
            $table->string('trigger_name')->unique();
            $table->boolean('active')->unsigned()->default(true);
            $table->boolean('include_as_default')->default(false);
            $table->json('include_types')->nullable();
            $table->json('exclude_types')->nullable();
            $table->json('exclude_year_groups')->nullable();
            $table->string('behaviourTriggerPeriod')->default('term');
            $table->integer('behaviourCountTrigger')->unsigned();
            $table->integer('behaviourPointTrigger')->unsigned();
            $table->string('detention_type_id')->nullable();
            $table->json('notification_email_addresses')->nullable();
            $table->set('notification_staff_roles', ['hoy', 'tutor'])->nullable();
            $table->longText('notification_message')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('detention_triggers');
    }
};
