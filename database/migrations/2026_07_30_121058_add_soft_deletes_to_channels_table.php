<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The unique index guarding one slug per workspace, recreated below as a
     * partial index so it only applies to live channels.
     */
    private const string SLUG_INDEX = 'channels_team_id_slug_unique';

    /**
     * Give channels a grace window between "deleted" and actually gone.
     *
     * Deleting a channel stamps `deleted_at`, which hides it everywhere at once
     * while a scheduled purge does the irreversible work later. The slug is the
     * route key and is unique per workspace, so a plain soft delete would keep a
     * deleted channel's name reserved for the whole window — an admin who
     * deleted #roadmap could neither recreate it nor be told why. Narrowing the
     * unique index to live rows releases the name the moment the channel
     * disappears; the restore path checks for a live holder before bringing one
     * back.
     */
    public function up(): void
    {
        Schema::table('channels', function (Blueprint $table): void {
            $table->softDeletes();
            $table->dropUnique(self::SLUG_INDEX);
        });

        DB::statement('CREATE UNIQUE INDEX '.self::SLUG_INDEX.' ON channels (team_id, slug) WHERE deleted_at IS NULL');
    }

    /**
     * Reverse the migrations.
     *
     * The plain unique index can only come back once no two rows share a slug,
     * so soft-deleted channels are purged first — which is what rolling this
     * feature back means anyway.
     */
    public function down(): void
    {
        DB::table('channels')->whereNotNull('deleted_at')->delete();

        DB::statement('DROP INDEX '.self::SLUG_INDEX);

        Schema::table('channels', function (Blueprint $table): void {
            $table->dropSoftDeletes();
            $table->unique(['team_id', 'slug'], self::SLUG_INDEX);
        });
    }
};
