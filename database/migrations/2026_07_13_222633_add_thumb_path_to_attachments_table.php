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
        Schema::table('attachments', function (Blueprint $table): void {
            // The downscaled, EXIF-stripped thumbnail's path on the same disk,
            // generated at upload for raster images only (null for SVG and every
            // non-image type). The timeline grid renders this in place of the
            // original; the lightbox still serves the original.
            $table->string('thumb_path')->nullable()->after('height');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('attachments', function (Blueprint $table): void {
            $table->dropColumn('thumb_path');
        });
    }
};
