<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('behaviour_notification_logs', 'rule_id')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE behaviour_notification_logs MODIFY rule_id BIGINT UNSIGNED NULL');
        } elseif ($driver === 'pgsql') {
            DB::statement('ALTER TABLE behaviour_notification_logs ALTER COLUMN rule_id DROP NOT NULL');
        }
    }

    public function down(): void
    {
        if (!Schema::hasColumn('behaviour_notification_logs', 'rule_id')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE behaviour_notification_logs MODIFY rule_id BIGINT UNSIGNED NOT NULL');
        } elseif ($driver === 'pgsql') {
            DB::statement('ALTER TABLE behaviour_notification_logs ALTER COLUMN rule_id SET NOT NULL');
        }
    }
};
