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
        Schema::table('data_exports', function (Blueprint $table): void {
            // Byte size of the built archive; null while pending, failed, or for
            // exports predating this column.
            $table->unsignedBigInteger('size_bytes')->nullable()->after('path');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('data_exports', function (Blueprint $table): void {
            $table->dropColumn('size_bytes');
        });
    }
};
