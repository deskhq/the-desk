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
        Schema::create('security_events', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            // The kind of security-relevant action (see App\Enums\SecurityEventType).
            $table->string('type');
            // Nullable, sized for IPv6; the request IP is not always resolvable.
            $table->string('ip_address', 45)->nullable();
            // The raw User-Agent header, parsed for display into browser/platform.
            $table->text('user_agent')->nullable();
            // Only meaningful for sign-in events: the IP+User-Agent combination
            // had not been seen on a prior sign-in, flagging unfamiliar access.
            $table->boolean('is_new_device')->default(false);
            $table->timestamps();

            // The activity log is always read newest-first for a single user.
            $table->index(['user_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('security_events');
    }
};
