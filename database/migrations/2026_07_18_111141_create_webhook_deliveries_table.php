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
        Schema::create('webhook_deliveries', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('webhook_subscription_id')->constrained()->cascadeOnDelete();
            // The event type delivered and the envelope id it carried, so a
            // receiver's log and this one can be reconciled.
            $table->string('event_type');
            $table->uuid('event_id');
            // Whether the endpoint answered 2xx, and the status code it returned
            // (null when the request never completed — a timeout or connection
            // error), plus a short error summary for a failed attempt.
            $table->boolean('succeeded');
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->string('error')->nullable();
            $table->timestamps();

            // The show endpoint reads a subscription's recent attempts newest-first.
            $table->index(['webhook_subscription_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('webhook_deliveries');
    }
};
