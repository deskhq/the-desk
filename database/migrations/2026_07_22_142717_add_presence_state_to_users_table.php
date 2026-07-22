<?php

use App\Enums\PresenceState;
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
        Schema::table('users', function (Blueprint $table): void {
            // The user's *manual* away override, and only that: auto-idle is
            // derived per connection in the cache-backed PresenceRegistry, since
            // two tabs of one account can disagree about being idle. Away here
            // wins over every connection, and survives reconnects until unset.
            $table->string('presence_state', 16)
                ->default(PresenceState::Active->value)
                ->after('status_expires_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('presence_state');
        });
    }
};
