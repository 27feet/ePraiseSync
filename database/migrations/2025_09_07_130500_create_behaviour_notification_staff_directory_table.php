<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('behaviour_notification_staff_directory', function (Blueprint $table) {
            $table->id();
            $table->string('display_name');                 // As it appears in the feeds (e.g., "Jo Smith")
            $table->string('email')->nullable();            // Lowercased later in import
            $table->string('normalized_name')->nullable();  // e.g., "jo smith" (for matching)
            $table->timestamps();

            // Keep index names short for MySQL/MariaDB
            $table->unique(['email'], 'bnsd_email_u');            // multiple NULLs are allowed
            $table->index(['normalized_name'], 'bnsd_norm_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('behaviour_notification_staff_directory');
    }
};
