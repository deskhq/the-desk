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
            // Whether the user shares their read position with channel peers, powering
            // the "Seen by" affordance. Default on; turning it off stops the user from
            // broadcasting or exposing where they've read up to (they can still see others').
            $table->boolean('share_read_receipts')->default(true)->after('chime_sound');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('share_read_receipts');
        });
    }
};
