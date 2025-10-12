<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('behaviour_notification_rules', function (Blueprint $table) {
            if (!Schema::hasColumn('behaviour_notification_rules', 'mode')) {
                $table->string('mode', 32)->default('daily_batch')->index();
            }
            if (!Schema::hasColumn('behaviour_notification_rules', 'metric')) {
                $table->string('metric', 16)->default('count');
            }
            if (!Schema::hasColumn('behaviour_notification_rules', 'categories')) {
                $table->json('categories')->nullable();
            }
            if (!Schema::hasColumn('behaviour_notification_rules', 'year_groups')) {
                $table->json('year_groups')->nullable();
            }
            // template_key already exists per your snapshot; keep as-is.
        });
    }

    public function down(): void
    {
        Schema::table('behaviour_notification_rules', function (Blueprint $table) {
            if (Schema::hasColumn('behaviour_notification_rules', 'mode')) $table->dropColumn('mode');
            if (Schema::hasColumn('behaviour_notification_rules', 'metric')) $table->dropColumn('metric');
            if (Schema::hasColumn('behaviour_notification_rules', 'categories')) $table->dropColumn('categories');
            if (Schema::hasColumn('behaviour_notification_rules', 'year_groups')) $table->dropColumn('year_groups');
        });
    }
};
