<?php

declare(strict_types=1);

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
        Schema::table('messages', function (Blueprint $table): void {
            // Which API token produced this row — the REST API's counterpart to
            // the `incoming_webhook_id` beside it. A bot holds as many tokens as
            // it has been minted, so the author alone cannot tell an admin which
            // credential to revoke; this makes revocation targetable at the
            // granularity the credential is issued at.
            //
            // Null on every other path (human sends, webhook ingest, system
            // messages) and null on API messages posted before this column
            // existed, which cannot be attributed retroactively.
            //
            // A plain `foreignId`, not the `foreignUuid` its webhook sibling
            // uses: Sanctum's `personal_access_tokens` is keyed by `$table->id()`,
            // an auto-increment bigint. That type split is why the two
            // credentials stay two columns rather than collapsing into a single
            // polymorphic "posted via" pair — one shared id column could hold
            // both only as a string, giving up the foreign key and the
            // `nullOnDelete` below with it.
            //
            // `nullOnDelete` so revoking a token never cascades into message
            // history — the trail thins, the messages stay.
            $table->foreignId('token_id')
                ->nullable()
                ->after('incoming_webhook_id')
                ->constrained('personal_access_tokens')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('token_id');
        });
    }
};
