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
        Schema::create('incoming_webhooks', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('team_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('channel_id')->constrained()->cascadeOnDelete();
            // The bot the posted message is authored by.
            $table->foreignUuid('bot_id')->constrained('users')->cascadeOnDelete();
            // The actor is retained for the audit trail: deleting them nulls the
            // reference rather than cascading the webhook away.
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name');
            // The opaque URL credential is never stored in plaintext: only its
            // sha256 hash is kept, and lookups hash the incoming token to match.
            $table->string('token_hash')->unique();
            // Optional shared secret for HMAC request signing, encrypted at rest.
            $table->text('signing_secret')->nullable();
            // A revoked webhook is kept for the audit trail but no longer resolves.
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('incoming_webhooks');
    }
};
