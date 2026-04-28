<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('demerits', function (Blueprint $table) {
            $table->string('checksum', 191)->nullable()->after('arbor_detention_id');
        });

        Schema::table('interventions', function (Blueprint $table) {
            $table->string('checksum', 191)->nullable()->after('arbor_detention_id');
        });
    }

    public function down(): void
    {
        Schema::table('demerits', function (Blueprint $table) {
            $table->dropColumn('checksum');
        });
        Schema::table('interventions', function (Blueprint $table) {
            $table->dropColumn('checksum');
        });
    }
};
