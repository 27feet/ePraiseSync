<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        foreach (['points', 'demerits', 'interventions'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->unsignedSmallInteger('sync_attempts')->default(0)->after('missing_runs');
                $table->dateTime('sync_last_failed_at')->nullable()->after('sync_attempts');
                $table->dateTime('sync_next_retry_at')->nullable()->after('sync_last_failed_at');
            });
        }
    }

    public function down(): void
    {
        foreach (['points', 'demerits', 'interventions'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropColumn(['sync_attempts', 'sync_last_failed_at', 'sync_next_retry_at']);
            });
        }
    }
};
