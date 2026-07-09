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
        Schema::table('messages', function (Blueprint $table) {
            // The thread this reply belongs to, pointing at the thread's root
            // message, or null for a root/normal message. Force-deleting the root
            // nulls the reference; a soft-deleted root keeps it so the thread
            // survives as a tombstone. Threads are one level deep — a reply's root
            // is always itself a root (thread_root_id null).
            $table->foreignUuid('thread_root_id')
                ->nullable()
                ->after('reply_to_id')
                ->constrained('messages')
                ->nullOnDelete();

            // A thread reply that is also surfaced in the main channel timeline
            // ("Also send to #channel"). Meaningless on root/normal messages.
            $table->boolean('sent_to_channel')
                ->default(false)
                ->after('thread_root_id');

            // Aggregate thread metadata denormalized onto the root message so the
            // main timeline can render the "N replies" affordance without a
            // per-row count. Increment-only: deleted replies stay as tombstones,
            // so the count keeps matching the rows the thread view shows.
            $table->unsignedInteger('reply_count')
                ->default(0)
                ->after('sent_to_channel');
            $table->timestamp('last_reply_at')
                ->nullable()
                ->after('reply_count');

            // Lists a thread's replies in order.
            $table->index(['thread_root_id', 'id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropIndex(['thread_root_id', 'id']);
            $table->dropConstrainedForeignId('thread_root_id');
            $table->dropColumn(['sent_to_channel', 'reply_count', 'last_reply_at']);
        });
    }
};
