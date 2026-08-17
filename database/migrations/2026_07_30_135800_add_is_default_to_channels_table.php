<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Marks a channel as one every new workspace member is joined to. A flag on
     * the channel rather than a pivot on the team: a channel belongs to exactly
     * one workspace, so a join table would only ever hold the same fact twice.
     *
     * The protected #general is a default in code and carries no flag, so it
     * cannot be un-defaulted; every other channel starts false, which leaves the
     * joining behaviour of an existing workspace unchanged.
     */
    public function up(): void
    {
        Schema::table('channels', function (Blueprint $table): void {
            $table->boolean('is_default')->default(false)->after('visibility');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('channels', function (Blueprint $table): void {
            $table->dropColumn('is_default');
        });
    }
};
