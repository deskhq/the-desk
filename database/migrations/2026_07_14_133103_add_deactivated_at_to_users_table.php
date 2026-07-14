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
            // When a directory (SCIM) pushes a deactivation the account is
            // tombstoned here rather than hard-deleted, so history is retained
            // while access is revoked. Null means active; a timestamp records
            // when the account was deactivated, and reactivation clears it. This
            // is deliberately distinct from `is_tombstone`, which flags the
            // single shared "Deleted User" placeholder (see User::tombstone()).
            $table->timestamp('deactivated_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('deactivated_at');
        });
    }
};
