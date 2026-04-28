<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('behaviour_rule_groups', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('source'); // demerit|intervention|point
            $table->json('categories')->nullable();
            $table->string('timeframe')->default('rolling_school_days'); // term|rolling_school_days|academic_year|today
            $table->integer('rolling_days')->nullable();
            $table->string('metric')->default('count'); // count|sum
            $table->string('mode')->default('daily_batch'); // daily_batch|realtime_today|digest
            $table->boolean('reset_on_term_change')->default(true);
            $table->boolean('mute_lower_on_first_run')->default(false);
            $table->boolean('digest_attach_csv')->default(false);
            $table->json('digest_fields')->nullable(); // columns to include in digest/table
            $table->json('staff_roles')->nullable();
            $table->json('extra_emails')->nullable();
            $table->boolean('recipient_staff')->default(true);
            $table->boolean('recipient_parents')->default(false); // digest should keep this false
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_evaluated_at')->nullable();
            $table->timestamps();
        });

        Schema::create('behaviour_rule_levels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('behaviour_rule_group_id')->constrained('behaviour_rule_groups')->cascadeOnDelete();
            $table->integer('threshold'); // e.g. 50/100/150
            $table->string('template_key');
            $table->boolean('recipient_staff')->default(true);
            $table->boolean('recipient_parents')->default(false);
            $table->json('staff_roles')->nullable();
            $table->json('extra_emails')->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::table('behaviour_notification_logs', function (Blueprint $table) {
            $table->foreignId('behaviour_rule_group_id')->nullable()->after('rule_id');
            $table->foreignId('behaviour_rule_level_id')->nullable()->after('behaviour_rule_group_id');
        });
    }

    public function down(): void
    {
        Schema::table('behaviour_notification_logs', function (Blueprint $table) {
            $table->dropColumn(['behaviour_rule_group_id', 'behaviour_rule_level_id']);
        });
        Schema::dropIfExists('behaviour_rule_levels');
        Schema::dropIfExists('behaviour_rule_groups');
    }
};
