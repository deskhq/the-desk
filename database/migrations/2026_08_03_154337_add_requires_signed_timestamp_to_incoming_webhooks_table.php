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
        Schema::table('incoming_webhooks', function (Blueprint $table): void {
            // Whether this webhook's senders must sign `"{timestamp}.{body}"` and
            // send that timestamp alongside the digest. Newly created webhooks do;
            // the rows below were minted against the body-only scheme, and their
            // senders cannot be updated by a migration.
            $table->boolean('requires_signed_timestamp')->default(true);
        });

        // Adding the column stamped every existing row with the new default, so
        // walk them back: a webhook that predates the scheme keeps working under
        // the old one until its operator revokes it and creates a replacement.
        //
        // Only rows that predate the column may be walked back, and on Postgres
        // that is what happens: the migration runs inside a transaction, so the
        // ALTER above holds its exclusive lock until this UPDATE commits, and a
        // webhook created in the meantime blocks and lands with the new default
        // rather than being swept into the exemption.
        DB::table('incoming_webhooks')->update(['requires_signed_timestamp' => false]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('incoming_webhooks', function (Blueprint $table): void {
            $table->dropColumn('requires_signed_timestamp');
        });
    }
};
