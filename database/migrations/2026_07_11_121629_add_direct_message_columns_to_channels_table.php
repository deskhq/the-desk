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
        Schema::table('channels', function (Blueprint $table): void {
            // Distinguishes ordinary channels from 1:1 direct messages (with
            // `group_direct` reserved for a future group-DM issue). Defaulting to
            // `standard` keeps every existing row a normal channel.
            $table->string('type')->default('standard')->after('visibility');
            // Sorted, colon-joined participant UUIDs (a single UUID for a self-DM).
            // Null for standard channels; the unique index below makes it the
            // canonical dedup key so exactly one DM exists per pair per team.
            $table->string('dm_key')->nullable()->after('type');

            // DMs have no name; standard channels still require one at the app layer.
            $table->string('name')->nullable()->change();

            $table->unique(['team_id', 'dm_key']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('channels', function (Blueprint $table): void {
            $table->dropUnique(['team_id', 'dm_key']);
            $table->dropColumn(['type', 'dm_key']);
            $table->string('name')->nullable(false)->change();
        });
    }
};
