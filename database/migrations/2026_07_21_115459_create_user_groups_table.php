<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('user_groups', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('team_id')->constrained()->cascadeOnDelete();
            // The display name ("Dev Team") and the handle typed after `@`
            // ("dev-team"). The slug is always stored lowercased and kebab-cased,
            // which is what makes the unique index case-insensitive in practice.
            $table->string('name');
            $table->string('slug');
            $table->timestamps();

            // A handle is unique within a workspace; deleting a group frees it for
            // reuse and makes existing `@[slug](group:id)` tokens in old messages
            // fall back to plain text.
            $table->unique(['team_id', 'slug']);
            // The management page and the shared mentionable-groups list both load
            // the whole set for a team.
            $table->index('team_id');
        });

        Schema::create('user_group_user', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('user_group_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            // Membership is a set: a user appears in a group at most once. Removing
            // them from the team cascades them out of that team's groups.
            $table->unique(['user_group_id', 'user_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_group_user');
        Schema::dropIfExists('user_groups');
    }
};
