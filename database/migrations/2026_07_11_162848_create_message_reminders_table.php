<?php

use App\Enums\MessageReminderStatus;
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
        Schema::create('message_reminders', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('message_id')->constrained('messages')->cascadeOnDelete();
            $table->timestamp('remind_at');
            $table->string('status')->default(MessageReminderStatus::Pending->value);
            // When the per-minute dispatcher flipped a due row to Fired and pushed
            // the nudge; null while still pending.
            $table->timestamp('fired_at')->nullable();
            $table->timestamps();

            // A user keeps at most one reminder per message: setting a new one
            // reuses (and re-arms) the existing row rather than stacking nudges.
            $table->unique(['user_id', 'message_id']);
            // The per-minute dispatcher scans WHERE status = ? AND remind_at <= ?.
            $table->index(['status', 'remind_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('message_reminders');
    }
};
