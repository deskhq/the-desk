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
            // When the user finished (or dismissed) the first-run onboarding tour.
            // Null means they have never completed it, which gates the auto-starting
            // tour and the brand-new-workspace welcome empty state.
            $table->timestamp('onboarding_completed_at')->nullable()->after('share_read_receipts');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('onboarding_completed_at');
        });
    }
};
