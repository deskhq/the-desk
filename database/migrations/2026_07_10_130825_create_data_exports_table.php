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
        Schema::create('data_exports', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            // The lifecycle state of the export (see App\Enums\DataExportStatus).
            $table->string('status');
            // Path on the private disk once the archive is built; null while pending or failed.
            $table->string('path')->nullable();
            // When the archive is purged and the download link stops working.
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            // The Data & privacy panel reads the user's latest export.
            $table->index(['user_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('data_exports');
    }
};
