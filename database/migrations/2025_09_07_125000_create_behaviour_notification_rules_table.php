<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('behaviour_notification_rules', function (Blueprint $table) {
            $table->id();

            // What we’re counting
            $table->string('source', 32);          // 'demerit' | 'intervention'
            $table->string('category');            // e.g. 'Uniform', 'Late', 'Skirt'

            // When we’re counting
            $table->string('timeframe', 32);       // 'term' | 'rolling_school_days'
            $table->unsignedSmallInteger('rolling_days')->nullable(); // used when timeframe='rolling_school_days'

            // Threshold (one row per threshold, e.g. 5 / 10 / 15 / 20)
            $table->unsignedSmallInteger('threshold');

            // Reset behaviour (true for term-bound thresholds like your spec)
            $table->boolean('reset_on_term_change')->default(true);

            // Who to notify (simple flags + optional targeting detail)
            $table->boolean('recipient_parents')->default(false);
            $table->boolean('recipient_staff')->default(false);

            // Staff targeting (e.g. ["tutor","head_of_year"])
            $table->json('staff_roles')->nullable();

            // Extra fixed email recipients (array of strings)
            $table->json('extra_emails')->nullable();

            // Which template to use (you’ll map this to your template store)
            $table->string('template_key');

            // Rule activation
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            // Helpful indexes
            $table->index(['source', 'category']);
            $table->index(['timeframe']);
            $table->index(['threshold']);
            $table->index(['is_active']);
            $table->index(['source', 'category', 'threshold', 'is_active'], 'bnr_source_cat_threshold_active_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('behaviour_notification_rules');
    }
};
