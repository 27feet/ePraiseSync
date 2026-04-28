<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('points', function (Blueprint $table) {
            $table->string('synced_checksum', 191)->nullable()->after('checksum');
        });
        Schema::table('demerits', function (Blueprint $table) {
            $table->string('synced_checksum', 191)->nullable()->after('checksum');
        });
        Schema::table('interventions', function (Blueprint $table) {
            $table->string('synced_checksum', 191)->nullable()->after('checksum');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('points', function (Blueprint $table) {
            $table->dropColumn('synced_checksum');
        });
        Schema::table('demerits', function (Blueprint $table) {
            $table->dropColumn('synced_checksum');
        });
        Schema::table('interventions', function (Blueprint $table) {
            $table->dropColumn('synced_checksum');
        });
    }
};
