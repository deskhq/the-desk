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
        Schema::create('message_pins', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            // One pin per message: the unique constraint makes the pin toggle
            // idempotent, and a message force-delete cascades its pin away.
            $table->foreignUuid('message_id')->unique()->constrained()->cascadeOnDelete();
            // Denormalized so the channel's pin count and pins list never join
            // through `messages`; a channel delete cascades its pins away.
            $table->foreignUuid('channel_id')->constrained()->cascadeOnDelete();
            // Who pinned it, for the "Pinned by :name" attribution. Reassigned to
            // the retained "Deleted User" tombstone when the pinner's account is
            // deleted (see AccountDeleter), so a pin never dangles or vanishes.
            $table->foreignUuid('pinned_by')->constrained('users');
            // No `updated_at`: a pin is created and destroyed, never edited. The
            // pins list orders on this timestamp (most-recently-pinned first).
            $table->timestamp('created_at')->nullable();

            $table->index('channel_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('message_pins');
    }
};
