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
            // A one-day snooze of the quiet-hours schedule: while this instant
            // is ahead the evaluator ignores the recurring window, then the
            // schedule resumes on its own. Set to the instant the running
            // window next closes, so it can never outlive tonight's window.
            $table->timestamp('dnd_schedule_snoozed_until')->nullable()->after('dnd_ends_at');

            // The scheduled sweep scans for lapsed snoozes every minute.
            $table->index('dnd_schedule_snoozed_until');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['dnd_schedule_snoozed_until']);
            $table->dropColumn('dnd_schedule_snoozed_until');
        });
    }
};
