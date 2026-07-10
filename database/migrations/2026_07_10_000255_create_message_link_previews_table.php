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
        Schema::create('message_link_previews', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('message_id')->constrained()->cascadeOnDelete();
            $table->text('url');
            $table->string('status')->default('pending');
            $table->string('title')->nullable();
            $table->text('description')->nullable();
            $table->text('image_url')->nullable();
            $table->string('site_name')->nullable();
            $table->unsignedTinyInteger('position');
            $table->timestamps();

            // One preview per slot; the extractor re-syncs positions on edit.
            $table->unique(['message_id', 'position']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('message_link_previews');
    }
};
