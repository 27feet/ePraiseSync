<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Recreate from scratch to remove drift
        Schema::dropIfExists('behaviour_notification_logs');

        Schema::create('behaviour_notification_logs', function (Blueprint $table) {
            $table->id();

            // Who/what rule fired
            $table->unsignedBigInteger('rule_id');
            $table->unsignedBigInteger('student_id');

            // What was counted (so we can filter/inspect without joining the rule)
            $table->string('source', 16);   // 'demerit' | 'intervention'
            $table->string('category');     // e.g. 'Uniform', 'Skirt'

            // Which threshold was hit (e.g. 5/10/15/20)
            $table->unsignedSmallInteger('hit_threshold');

            // Window info (supports both term + rolling)
            $table->string('window_type', 32)->nullable(); // 'term' | 'rolling_school_days'
            $table->date('window_start_date');
            $table->date('window_end_date');
            $table->unsignedBigInteger('window_term_id')->nullable();
            $table->unsignedSmallInteger('window_school_days_lookback')->nullable();

            // Snapshots
            $table->json('source_snapshot')->nullable();
            $table->json('recipients_snapshot')->nullable();

            $table->timestamps();

            // Indexes (short names for MariaDB)
            $table->index(['rule_id', 'student_id', 'window_start_date', 'window_end_date', 'hit_threshold'], 'bnl_rule_stu_win_thr_idx');
            $table->index(['window_term_id'], 'bnl_term_idx');
            $table->index(['created_at'], 'bnl_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('behaviour_notification_logs');
    }
};
