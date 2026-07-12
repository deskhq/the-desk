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
        Schema::create('thread_reads', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            // The thread's root message. Force-deleting the root cascades this
            // pointer away; a soft-deleted root keeps its row so the thread — and
            // its read state — survive as a tombstone.
            $table->foreignUuid('thread_root_id')->constrained('messages')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            // The last reply the user has seen in this thread. Unconstrained, like
            // channel_members.last_read_message_id, so it can point at a since
            // soft-deleted reply without tripping a foreign key. Null until the
            // user first opens the thread (everything then counts as unread).
            $table->uuid('last_read_reply_id')->nullable();
            $table->timestamps();

            // One read pointer per user per thread.
            $table->unique(['thread_root_id', 'user_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('thread_reads');
    }
};
