<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        foreach (['points', 'demerits', 'interventions'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->unsignedSmallInteger('sync_last_error_status')->nullable()->after('sync_next_retry_at');
                $table->text('sync_last_error_body')->nullable()->after('sync_last_error_status');
            });
        }
    }

    public function down(): void
    {
        foreach (['points', 'demerits', 'interventions'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropColumn(['sync_last_error_status', 'sync_last_error_body']);
            });
        }
    }
};
