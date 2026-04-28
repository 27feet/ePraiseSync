<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // For each rule, if categories is null and category is set, copy it into categories array
        DB::table('behaviour_notification_rules')
            ->whereNull('categories')
            ->whereNotNull('category')
            ->update([
                'categories' => DB::raw("JSON_ARRAY(category)"),
            ]);
    }

    public function down(): void
    {
        // no-op; leaving categories populated is safe
    }
};
