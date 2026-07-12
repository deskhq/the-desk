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
            // Marks the single retained "Deleted User" account that authored
            // messages are reassigned to when their real author deletes their
            // account, so channel history stays coherent (see App\Support\AccountDeleter).
            $table->boolean('is_tombstone')->default(false);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('is_tombstone');
        });
    }
};
