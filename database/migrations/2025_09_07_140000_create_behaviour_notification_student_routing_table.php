<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('behaviour_notification_student_routing', function (Blueprint $table) {
            $table->id();

            // Link to your local Student rows
            $table->string('student_record_id')->comment('Local Student.record_id (ePraise id)');
            $table->string('student_arbor_id')->nullable()->comment('Local Student.arbor_id (UPN)');

            // Raw strings as they appear in the tutors/HOY feed (for auditing/debug)
            $table->text('tutor_names_raw')->nullable();
            $table->text('hoy_names_raw')->nullable();

            // Normalized tokens we parsed from the raw strings (lowercased, spaces collapsed)
            $table->json('tutor_normalized_names')->nullable();
            $table->json('hoy_normalized_names')->nullable();

            // Resolution onto staff directory + final email targets for each role
            // (IDs refer to behaviour_notification_staff_directory.id)
            $table->json('tutor_staff_ids')->nullable();
            $table->json('hoy_staff_ids')->nullable();

            $table->json('tutor_emails')->nullable();
            $table->json('hoy_emails')->nullable();

            // Slot for any fixed extras (rare, but keeps everything in one row if needed)
            $table->json('extra_staff_emails')->nullable();

            $table->timestamps();

            // Keep index names short for MySQL/MariaDB limits
            $table->unique(['student_record_id'], 'bnsr_student_rec_u');
            $table->index(['student_arbor_id'], 'bnsr_arbor_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('behaviour_notification_student_routing');
    }
};
