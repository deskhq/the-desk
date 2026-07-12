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
            // The parent this message quotes inline, or null for a normal
            // message. A quoted parent that is later force-deleted nulls the
            // reference; a soft-deleted parent keeps it so the client renders a
            // "message deleted" stub in the quote.
            $table->foreignUuid('reply_to_id')
                ->nullable()
                ->after('client_uuid')
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
            $table->dropConstrainedForeignId('reply_to_id');
        });
    }
};
