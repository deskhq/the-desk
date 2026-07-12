<?php

use App\Enums\NotificationLevel;
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
        Schema::table('channel_members', function (Blueprint $table): void {
            // Per-member notification preferences for the channel. `muted` dims the
            // channel and is the strongest silence; `notification_level` tunes which
            // arrivals raise a badge (see App\Enums\NotificationLevel).
            $table->boolean('muted')->default(false)->after('last_read_message_id');
            $table->string('notification_level')->default(NotificationLevel::All->value)->after('muted');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('channel_members', function (Blueprint $table): void {
            $table->dropColumn(['muted', 'notification_level']);
        });
    }
};
