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
        Schema::table('channel_members', function (Blueprint $table): void {
            // Whether the member has starred (favorited) the channel, pinning it to
            // the sidebar's "Starred" section. Per member, so each user curates
            // their own favorites independently.
            $table->boolean('starred')->default(false)->after('draft');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('channel_members', function (Blueprint $table): void {
            $table->dropColumn('starred');
        });
    }
};
