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
        Schema::create('polls', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            // One poll per message. This DB cascade fires only on a hard delete of
            // the message row; the app's ordinary message deletion is a soft delete
            // (a tombstone), so DeleteMessage removes the poll explicitly there. The
            // cascade still guarantees no orphaned poll survives a force-delete.
            $table->foreignUuid('message_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('question');
            $table->boolean('allow_multiple')->default(false);
            $table->boolean('is_anonymous')->default(false);
            // Null while the poll accepts votes; set once a creator or admin closes
            // it, which freezes the tally and rejects further votes.
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('polls');
    }
};
