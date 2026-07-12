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
            // The message this one forwards into its channel, or null for a
            // normal message. Unlike reply_to_id, the source lives in another
            // channel (any the author belongs to). A force-deleted source nulls
            // the reference; a soft-deleted source keeps it so the client renders
            // a "message deleted" stub in the forwarded quote.
            $table->foreignUuid('forwarded_from_id')
                ->nullable()
                ->after('reply_to_id')
                ->constrained('messages')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('forwarded_from_id');
        });
    }
};
