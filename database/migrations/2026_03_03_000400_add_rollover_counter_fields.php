<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('behaviour_rule_groups', function (Blueprint $table) {
            $table->string('counter_mode')->default('cumulative')->after('metric'); // cumulative|rollover
            $table->unsignedInteger('rollover_every')->nullable()->after('counter_mode');
        });

        Schema::table('behaviour_notification_logs', function (Blueprint $table) {
            $table->unsignedInteger('counter_cycle')->nullable()->after('hit_threshold');
            $table->index(
                ['behaviour_rule_group_id', 'student_id', 'window_start_date', 'window_end_date', 'counter_cycle'],
                'bnl_group_stu_window_cycle_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::table('behaviour_notification_logs', function (Blueprint $table) {
            $table->dropIndex('bnl_group_stu_window_cycle_idx');
            $table->dropColumn('counter_cycle');
        });

        Schema::table('behaviour_rule_groups', function (Blueprint $table) {
            $table->dropColumn(['counter_mode', 'rollover_every']);
        });
    }
};
