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
        Schema::table('channel_members', function (Blueprint $table) {
            // The custom section the member has filed this channel under, or null
            // for the default "Channels" group. Deleting the section returns the
            // channel to the default group rather than dropping the membership.
            $table->foreignUuid('section_id')->nullable()->after('starred')
                ->constrained('channel_sections')->nullOnDelete();
            // The channel's manual order within whichever group it renders in
            // (Starred, a custom section, or the default list). Ties fall back to
            // the alphabetical name order the sidebar query already applies.
            $table->integer('position')->default(0)->after('section_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('channel_members', function (Blueprint $table) {
            $table->dropConstrainedForeignId('section_id');
            $table->dropColumn('position');
        });
    }
};
