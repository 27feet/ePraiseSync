<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('behaviour_notification_logs') || !Schema::hasColumn('behaviour_notification_logs', 'category')) {
            return;
        }

        $driver = DB::getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE behaviour_notification_logs MODIFY category TEXT NOT NULL');
            return;
        }

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE behaviour_notification_logs ALTER COLUMN category TYPE TEXT');
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('behaviour_notification_logs') || !Schema::hasColumn('behaviour_notification_logs', 'category')) {
            return;
        }

        $driver = DB::getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE behaviour_notification_logs MODIFY category VARCHAR(255) NOT NULL');
            return;
        }

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE behaviour_notification_logs ALTER COLUMN category TYPE VARCHAR(255)');
        }
    }
};
