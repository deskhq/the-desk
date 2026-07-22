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
            // The user's self-set custom status. The emoji is an opaque picker
            // value — a native glyph or a `:name:` custom-emoji shortcode — so it
            // is sized for the longest shortcode rather than a single character.
            // A null `status_expires_at` means "don't clear"; a past one means the
            // status has lapsed and reads as absent until the sweep nulls it.
            $table->string('status_emoji', 64)->nullable()->after('phone');
            $table->string('status_text', 100)->nullable()->after('status_emoji');
            $table->timestamp('status_expires_at')->nullable()->after('status_text');

            // The scheduled sweep scans for lapsed statuses every minute.
            $table->index('status_expires_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['status_expires_at']);
            $table->dropColumn(['status_emoji', 'status_text', 'status_expires_at']);
        });
    }
};
