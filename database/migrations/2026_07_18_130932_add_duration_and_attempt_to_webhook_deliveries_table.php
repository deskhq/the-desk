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
            // Wall-clock latency of the delivery request in milliseconds (null
            // when the request never completed), and the 1-based attempt number
            // it represents — both surfaced in the management delivery log so an
            // operator can see how slow and how many retries a subscription took.
            $table->unsignedInteger('duration_ms')->nullable()->after('response_status');
            $table->unsignedSmallInteger('attempt')->default(1)->after('duration_ms');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('webhook_deliveries', function (Blueprint $table): void {
            $table->dropColumn(['duration_ms', 'attempt']);
        });
    }
};
