<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('behaviour_notification_parent_contacts', function (Blueprint $table) {
            $table->id();

            // Student identifiers (either/both may be present depending on the feed)
            $table->unsignedBigInteger('student_record_id')->nullable();
            $table->unsignedBigInteger('student_arbor_id')->nullable();

            // Contact fields from the feed
            $table->string('title', 32)->nullable();   // e.g. "Mr", "Mrs", "Dr"
            $table->string('surname');                 // e.g. "Smith"
            $table->string('email');                   // as received
            $table->string('email_normalized');        // lower-cased for matching/upserts

            $table->timestamps();

            // Keep index names short (MySQL/MariaDB limits)
            $table->index(['student_record_id'], 'bnpc_sr_idx');
            $table->index(['student_arbor_id'], 'bnpc_sa_idx');

            // De-dupe per student/email (two partial uniques; NULLs are allowed & not considered equal)
            $table->unique(['student_record_id', 'email_normalized'], 'bnpc_sr_email_u');
            $table->unique(['student_arbor_id', 'email_normalized'], 'bnpc_sa_email_u');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('behaviour_notification_parent_contacts');
    }
};
