<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        foreach (['points', 'demerits', 'interventions'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->dateTime('last_seen_at')->nullable()->after('synced_at');
                $table->string('last_seen_run', 100)->nullable()->after('last_seen_at');
                $table->unsignedSmallInteger('missing_runs')->default(0)->after('last_seen_run');
            });
        }

        foreach (['check_points', 'check_demerits', 'check_interventions'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->dateTime('last_seen_at')->nullable()->after('date');
                $table->string('last_seen_run', 100)->nullable()->after('last_seen_at');
            });
        }
    }

    public function down(): void
    {
        foreach (['points', 'demerits', 'interventions'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->dropColumn(['last_seen_at', 'last_seen_run', 'missing_runs']);
            });
        }

        foreach (['check_points', 'check_demerits', 'check_interventions'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->dropColumn(['last_seen_at', 'last_seen_run']);
            });
        }
    }
};
