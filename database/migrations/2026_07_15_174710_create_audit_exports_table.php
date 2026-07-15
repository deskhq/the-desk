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
        Schema::create('audit_exports', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('team_id')->constrained()->cascadeOnDelete();
            // The admin who requested the export. Any current team admin may
            // download it; this only records who kicked it off. Nulled (not
            // cascaded) when that user is deleted so the evidence export, and the
            // file behind it, survive until its own retention window closes.
            $table->foreignUuid('requested_by')->nullable()->constrained('users')->nullOnDelete();
            // Which log the export draws from (see App\Enums\AuditExportLogType).
            $table->string('log_type');
            // The file format written (see App\Enums\AuditExportFormat).
            $table->string('format');
            // Inclusive whole-day bounds picked in the requester's timezone; null
            // on either side means unbounded (an all-time export when both null).
            $table->date('range_start')->nullable();
            $table->date('range_end')->nullable();
            // The lifecycle state of the export (see App\Enums\AuditExportStatus).
            $table->string('status');
            // Path on the private disk once the file is built; null while pending or failed.
            $table->string('path')->nullable();
            // When the file is purged and the download link stops working.
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            // The exports page reads a team's exports newest-first.
            $table->index(['team_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('audit_exports');
    }
};
