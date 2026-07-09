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
        Schema::create('channel_sections', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            // A custom section is owned by one user within one team, so each user
            // curates their own sidebar grouping independently. Both cascade so a
            // section is cleaned up when the user or team goes away.
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('team_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            // Order of the user's custom sections within the team, lowest first.
            $table->integer('position')->default(0);
            // Per-section collapse state. Built-in sections persist their collapse
            // on `users.collapsed_channel_sections`; custom sections carry their own.
            $table->boolean('collapsed')->default(false);
            $table->timestamps();

            $table->index(['user_id', 'team_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('channel_sections');
    }
};
