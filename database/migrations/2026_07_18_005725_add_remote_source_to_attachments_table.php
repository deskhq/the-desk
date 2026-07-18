<?php

use App\Enums\AttachmentSource;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add the remote-attachment variant to the attachments table. A remote
     * attachment (currently a Giphy GIF) carries no blob: its media is hotlinked
     * from a CDN via `remote_url`, so `disk`/`path`/`original_filename` become
     * nullable. `source` discriminates the two variants; `description` holds the
     * remote content description, surfaced as the rendered image's `alt` text.
     */
    public function up(): void
    {
        Schema::table('attachments', function (Blueprint $table): void {
            $table->string('source')->default(AttachmentSource::Upload->value)->after('channel_id');
            // The resolved remote CDN media URL, used directly as the `<img src>`;
            // null for an uploaded blob (which serves through the download route).
            $table->string('remote_url')->nullable()->after('height');
            // The remote content description, used as the rendered `<img alt>`;
            // null for uploads (which have no provider-supplied description).
            $table->string('description')->nullable()->after('remote_url');

            // A remote attachment has no blob, so the blob columns are nullable.
            $table->string('disk')->nullable()->change();
            $table->string('path')->nullable()->change();
            $table->string('original_filename')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('attachments', function (Blueprint $table): void {
            $table->dropColumn(['source', 'remote_url', 'description']);
            // Note: the blob columns are left nullable on rollback. Reverting them
            // to NOT NULL would fail if any remote row exists, and the create
            // migration's down() drops the whole table anyway.
        });
    }
};
