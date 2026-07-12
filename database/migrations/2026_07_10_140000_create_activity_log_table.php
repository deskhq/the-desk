<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Backs Spatie's activity log with the app's UUID conventions and a
     * `team_id` so the log can be scoped to a single workspace. The table is
     * treated as append-only at the model layer (see App\Models\AuditActivity).
     */
    public function up(): void
    {
        Schema::create('activity_log', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            // Groups entries by feature; always 'audit' for this application.
            $table->string('log_name')->nullable();
            // A human-readable label for the action (see App\Enums\AuditAction).
            $table->text('description');
            // The entity acted upon (channel, message, or target user).
            $table->nullableUuidMorphs('subject');
            // The admin/moderator who performed the action.
            $table->nullableUuidMorphs('causer');
            // The App\Enums\AuditAction value; the primary filter dimension.
            $table->string('event')->nullable();
            // Structured context to render a human sentence (names, old->new role).
            $table->json('properties')->nullable();
            // Spatie's attribute diff column; unused by the audit recorder.
            $table->json('attribute_changes')->nullable();
            // Present for Spatie batch logging; unused by the audit recorder.
            $table->uuid('batch_uuid')->nullable();
            // The workspace the action belongs to; the log is always team-scoped.
            $table->foreignUuid('team_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            // The viewer reads one team's log newest-first, optionally filtered by
            // action type or actor.
            $table->index(['team_id', 'created_at']);
            $table->index(['team_id', 'event']);
            $table->index(['team_id', 'causer_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('activity_log');
    }
};
