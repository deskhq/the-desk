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
        Schema::table('channel_members', function (Blueprint $table): void {
            // The member's unsent composer text for the channel, persisted so it
            // survives navigation, reloads and other devices. Null means no
            // pending draft (the sidebar shows no draft cue).
            $table->text('draft')->nullable()->after('notification_level');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('channel_members', function (Blueprint $table): void {
            $table->dropColumn('draft');
        });
    }
};
