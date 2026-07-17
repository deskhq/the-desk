<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The storage-disk path of the user's uploaded avatar blob (and, by
     * convention, its thumbnail sibling). `avatar_url` holds the public,
     * browser-cacheable URL that every surface reads; this holds the path
     * behind it so the old blob can be deleted when the avatar is replaced or
     * removed. Null whenever the avatar is derived (Gravatar) rather than
     * uploaded.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('avatar_path')->nullable()->after('avatar_url');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('avatar_path');
        });
    }
};
