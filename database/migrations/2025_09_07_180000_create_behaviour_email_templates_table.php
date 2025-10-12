<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('behaviour_email_templates', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();          // e.g. 'uniform_5', 'late_10', 'skirt_20'
            $table->string('subject');                // subject line with tokens
            $table->longText('body_html');            // HTML body with tokens
            $table->longText('body_text')->nullable(); // optional plain text
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_active'], 'bet_active_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('behaviour_email_templates');
    }
};
