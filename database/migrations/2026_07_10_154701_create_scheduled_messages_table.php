<?php

use App\Enums\ScheduledMessageStatus;
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
        Schema::create('scheduled_messages', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('channel_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            // Generated when the message is scheduled and handed to PostMessage at
            // delivery, so the eventual send dedupes against the messages table's
            // unique (channel_id, client_uuid) exactly like an immediate send.
            $table->uuid('client_uuid');
            $table->text('body');
            // The inline quote target, resolved against the channel at delivery; a
            // since-deleted target is dropped rather than blocking the send.
            $table->foreignUuid('reply_to_id')->nullable()->constrained('messages')->nullOnDelete();
            $table->timestamp('send_at');
            $table->string('status')->default(ScheduledMessageStatus::Pending->value);
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            // Why a due row could not be delivered (archived channel, author removed),
            // surfaced for observability; null while pending or once sent.
            $table->string('failure_reason')->nullable();
            $table->timestamps();

            // The per-minute dispatcher scans WHERE status = ? AND send_at <= ?.
            $table->index(['status', 'send_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('scheduled_messages');
    }
};
