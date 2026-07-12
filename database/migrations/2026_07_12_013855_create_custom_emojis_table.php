<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('custom_emojis', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('team_id')->constrained()->cascadeOnDelete();
            // The uploader. Nulled rather than cascaded when they leave so a
            // departed member's emoji stays usable workspace-wide; "delete your
            // own" simply no longer applies to an orphaned row (admins revoke).
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name');
            $table->string('path');
            $table->timestamps();

            // The `:name:` shortcode is unique within a workspace; revoking an
            // emoji hard-deletes the row, which frees the name for reuse.
            $table->unique(['team_id', 'name']);
            // The registry page and the shared name->url map both load the whole
            // set for a team.
            $table->index('team_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('custom_emojis');
    }
};
