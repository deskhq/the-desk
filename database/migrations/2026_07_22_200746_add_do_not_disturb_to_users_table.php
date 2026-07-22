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
        Schema::table('users', function (Blueprint $table): void {
            // The user's do-not-disturb state, gating only the in-app chime. A
            // manual pause is an instant (`dnd_until` in the future = paused);
            // the recurring quiet-hours window is a daily start/end pair kept as
            // `HH:MM` strings evaluated in the user's own `timezone`, so the
            // window follows them when they travel. The two are independent —
            // either alone puts the user in DND.
            $table->timestamp('dnd_until')->nullable()->after('presence_state');
            $table->boolean('dnd_schedule_enabled')->default(false)->after('dnd_until');
            $table->string('dnd_starts_at', 5)->nullable()->after('dnd_schedule_enabled');
            $table->string('dnd_ends_at', 5)->nullable()->after('dnd_starts_at');

            // The scheduled sweep scans for lapsed pauses every minute.
            $table->index('dnd_until');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['dnd_until']);
            $table->dropColumn(['dnd_until', 'dnd_schedule_enabled', 'dnd_starts_at', 'dnd_ends_at']);
        });
    }
};
