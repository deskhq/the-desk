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
        Schema::table('incoming_webhooks', function (Blueprint $table): void {
            // The flag exempted webhooks that predated the timestamped signing
            // scheme from it, for one release, so their senders could be moved
            // over. Every signed webhook is held to that scheme now, so the
            // exemption has nothing left to express — and a column that outlives
            // the branch reading it is an exemption waiting to be honoured again.
            $table->dropColumn('requires_signed_timestamp');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('incoming_webhooks', function (Blueprint $table): void {
            // Rolling back restores the column, not the exemption: the code that
            // read it is gone, so every row lands on the scheme they all now use
            // rather than being swept back into a window that has closed.
            $table->boolean('requires_signed_timestamp')->default(true);
        });
    }
};
