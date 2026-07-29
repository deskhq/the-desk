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
        Schema::table('messages', function (Blueprint $table): void {
            // The per-message display identity an incoming webhook asked for, so
            // one webhook can post as many logical sources. A snapshot frozen at
            // post time — an old message renders as it did even after the webhook
            // is revoked or its bot renamed — which is why these live on the
            // message rather than being looked up through the webhook.
            //
            // They override only what is *displayed*: `user_id` still names the
            // bot that actually authored the row, so every surface keeps its
            // non-human marking and the real account stays reachable.
            $table->string('author_override_name')->nullable()->after('body');
            $table->string('author_override_avatar_url', 2048)->nullable()->after('author_override_name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table): void {
            $table->dropColumn(['author_override_name', 'author_override_avatar_url']);
        });
    }
};
