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
     * The long-form "what this channel is for", alongside the existing one-line
     * `topic`. It is `text` rather than `string` because it holds paragraphs;
     * the 1500-character cap is a validation rule, not a column width.
     */
    public function up(): void
    {
        Schema::table('channels', function (Blueprint $table): void {
            $table->text('description')->nullable()->after('topic');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('channels', function (Blueprint $table): void {
            $table->dropColumn('description');
        });
    }
};
