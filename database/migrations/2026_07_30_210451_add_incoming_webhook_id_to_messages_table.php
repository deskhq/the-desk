<?php

declare(strict_types=1);

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
            // Which incoming webhook produced this row. `user_id` names the bot
            // that authored it, but a bot holds as many webhooks as it has
            // (bot, channel) pairs — so the author alone cannot tell an admin
            // which credential to revoke. This closes that gap: revocation
            // becomes targetable at the granularity the credential is issued at.
            //
            // Null on every other path (human sends, the REST API, system
            // messages) and null on webhook messages posted before this column
            // existed, which cannot be attributed retroactively: nothing was
            // recorded, and guessing from `user_id` + `channel_id` would be wrong
            // for exactly the multi-hook bot that motivates the column.
            //
            // `nullOnDelete` so deleting a webhook never cascades into message
            // history — the trail thins, the messages stay.
            $table->foreignUuid('incoming_webhook_id')
                ->nullable()
                ->after('user_id')
                ->constrained()
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('incoming_webhook_id');
        });
    }
};
