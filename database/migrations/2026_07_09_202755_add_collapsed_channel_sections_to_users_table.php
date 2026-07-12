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
        Schema::table('users', function (Blueprint $table): void {
            // The sidebar section keys the user has collapsed (e.g. ["starred"]),
            // persisted so the collapsed/expanded layout follows them across
            // reloads and devices. Null is treated as "nothing collapsed".
            //
            // `jsonb`, not `json`: Postgres cannot apply the `SELECT DISTINCT
            // users.*` used by the thread-participants query to a `json` column
            // (it has no equality operator), whereas `jsonb` does.
            $table->jsonb('collapsed_channel_sections')->nullable()->after('chime_sound');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('collapsed_channel_sections');
        });
    }
};
