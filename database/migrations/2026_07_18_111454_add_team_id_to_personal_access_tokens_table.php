<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Bind a human personal access token to a single team.
     *
     * A bot token leaves this null — a bot is already team-scoped through its
     * `owner_team_id` — while a human PAT confines the token to one of the
     * person's teams, so it acts with the human's memberships and permissions
     * only within that team. The FK nulls on team deletion so a removed team
     * never orphans the row (the token still authenticates but, lacking a team,
     * fails every API authorization check).
     */
    public function up(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table): void {
            $table->foreignUuid('team_id')
                ->nullable()
                ->after('abilities')
                ->constrained()
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('team_id');
        });
    }
};
