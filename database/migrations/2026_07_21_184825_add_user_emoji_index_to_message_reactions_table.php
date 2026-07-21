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
        Schema::table('message_reactions', function (Blueprint $table): void {
            // The frequently-used ranking groups one user's reactions by emoji
            // (see App\Support\FrequentEmoji); the existing unique index leads
            // with `message_id`, so it cannot serve that scan.
            $table->index(['user_id', 'emoji']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('message_reactions', function (Blueprint $table): void {
            $table->dropIndex(['user_id', 'emoji']);
        });
    }
};
