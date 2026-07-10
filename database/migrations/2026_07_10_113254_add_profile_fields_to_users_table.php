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
        Schema::table('users', function (Blueprint $table) {
            // Optional self-service identity fields surfaced on the member profile.
            $table->string('pronouns')->nullable()->after('email');
            $table->string('title')->nullable()->after('pronouns');
            $table->string('phone')->nullable()->after('title');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['pronouns', 'title', 'phone']);
        });
    }
};
