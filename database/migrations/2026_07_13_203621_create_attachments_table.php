<?php

use App\Enums\AttachmentStatus;
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
        Schema::create('attachments', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            // Nullable: a pending upload exists before the message that claims it.
            // Set (and status flipped to attached) when the message is sent. A
            // message force-delete cascades to its attachments; a soft-deleted
            // message keeps its rows so the serve policy can still deny access.
            $table->foreignUuid('message_id')->nullable()->constrained()->cascadeOnDelete();
            // The uploader. Cascade-deleted with the account: an orphaned pending
            // file has no owner to authorize its later claim.
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            // The channel the file was uploaded to, captured at upload so both the
            // post-policy authorization and the serve authorization resolve before
            // any message exists.
            $table->foreignUuid('channel_id')->constrained()->cascadeOnDelete();
            $table->string('disk');
            $table->string('path');
            $table->string('original_filename');
            $table->string('mime_type');
            $table->unsignedBigInteger('size_bytes');
            // Pixel dimensions, captured via getimagesize() for images only; null
            // for every other file type.
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            // pending until claimed by a message, then attached. An explicit column
            // rather than message_id IS NULL so scheduled-message claiming (a later
            // child) plugs in without a migration rewrite.
            $table->string('status')->default(AttachmentStatus::Pending->value);
            $table->timestamps();
            $table->softDeletes();

            $table->index('message_id');
            // The serve/claim paths scope by channel; the pending-orphan GC scans
            // WHERE status = ? AND created_at < ?.
            $table->index('channel_id');
            $table->index(['status', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('attachments');
    }
};
