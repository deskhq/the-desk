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
        Schema::create('webhook_subscriptions', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('team_id')->constrained()->cascadeOnDelete();
            // The bot (or admin) that registered the subscription. Nulled — not
            // cascaded — when that user is deleted so the subscription keeps
            // delivering; only the "who created it" attribution is lost.
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            // Operator-facing label, echoed back on the API resource.
            $table->string('name');
            // The external URL each matching event is POSTed to.
            $table->string('url');
            // The signing secret (HMAC key), stored encrypted at rest and shown
            // to the integrator in plaintext only once, in the create response.
            $table->text('secret');
            // The events this subscription listens for (see App\Enums\WebhookEvent).
            $table->json('events');
            // Optional channel-id allow-list; null/empty means every channel in
            // the team. A message/reaction/member event only delivers when its
            // channel is in this list (or the list is unset).
            $table->json('channel_ids')->nullable();
            // The lifecycle state (see App\Enums\WebhookSubscriptionStatus).
            $table->string('status')->default('active');
            // Consecutive failed delivery attempts since the last success; reset
            // to zero on any 2xx. Reaching config('integrations.webhooks
            // .disable_after') auto-disables the subscription.
            $table->unsignedInteger('consecutive_failures')->default(0);
            // The last time a delivery succeeded, and when the platform disabled
            // the subscription — both surfaced so a dead endpoint is visible.
            $table->timestamp('last_success_at')->nullable();
            $table->timestamp('disabled_at')->nullable();
            $table->timestamps();

            // The API lists a team's subscriptions newest-first.
            $table->index(['team_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('webhook_subscriptions');
    }
};
