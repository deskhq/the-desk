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
        Schema::table('channel_members', function (Blueprint $table): void {
            // When the member closed (hid) this direct message from their sidebar.
            // A DM stays hidden only until a message arrives after this instant, so
            // a fresh reply re-surfaces it without any write on the message path.
            // Per member, so each side hides their own view independently.
            $table->timestamp('hidden_at')->nullable()->after('position');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('channel_members', function (Blueprint $table): void {
            $table->dropColumn('hidden_at');
        });
    }
};
