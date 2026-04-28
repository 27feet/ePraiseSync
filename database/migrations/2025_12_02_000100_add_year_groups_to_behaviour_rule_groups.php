<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('behaviour_rule_groups', function (Blueprint $table) {
            if (!Schema::hasColumn('behaviour_rule_groups', 'year_groups')) {
                $table->json('year_groups')->nullable()->after('categories');
            }
        });
    }

    public function down(): void
    {
        Schema::table('behaviour_rule_groups', function (Blueprint $table) {
            if (Schema::hasColumn('behaviour_rule_groups', 'year_groups')) {
                $table->dropColumn('year_groups');
            }
        });
    }
};
