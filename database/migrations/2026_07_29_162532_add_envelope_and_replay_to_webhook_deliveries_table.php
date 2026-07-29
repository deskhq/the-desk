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
        Schema::table('webhook_deliveries', function (Blueprint $table): void {
            // The exact envelope POSTed, kept so a past delivery can be re-signed
            // and re-sent verbatim. Nullable because attempts logged before
            // replay shipped carry none — those rows are simply not replayable.
            $table->jsonb('envelope')->nullable();
            // Whether this attempt was fired by a manual replay rather than by
            // the event itself. A replay reuses the original envelope id, so the
            // log already groups the two; this only records how the attempt was
            // triggered.
            $table->boolean('is_replay')->default(false);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('webhook_deliveries', function (Blueprint $table): void {
            $table->dropColumn(['envelope', 'is_replay']);
        });
    }
};
