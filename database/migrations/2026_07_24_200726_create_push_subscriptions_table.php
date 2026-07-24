<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The columns are the shape `laravel-notification-channels/webpush` reads —
     * its `PushSubscription` model and `HasPushSubscriptions` trait bind to these
     * names — with one deviation from the package's published stub: the morph is
     * a `uuidMorphs`, because every model in this app (the `User` that owns a
     * subscription included) keys on a UUID string rather than an auto-increment
     * integer.
     *
     * One row is one browser on one device: a push endpoint is issued per
     * service-worker registration, so enabling push on a laptop and on a phone
     * yields two rows and either can be revoked on its own. The endpoint is
     * unique because the push service already treats it as the subscription's
     * identity — re-subscribing the same browser has to update the existing row
     * rather than add a second one.
     */
    public function up(): void
    {
        Schema::create('push_subscriptions', function (Blueprint $table): void {
            // The package's model is a plain auto-increment Eloquent model with
            // no HasUuids, so the primary key stays a bigint; only the owning
            // reference has to speak this app's UUID keys.
            $table->bigIncrements('id');
            $table->uuidMorphs('subscribable', 'push_subscriptions_subscribable_morph_idx');
            $table->string('endpoint', 500)->unique();
            // The browser's ECDH public key and auth secret. Every payload is
            // encrypted to them, so the push service relays ciphertext it cannot
            // read. Nullable because the spec allows an unencrypted subscription.
            $table->string('public_key')->nullable();
            $table->string('auth_token')->nullable();
            $table->string('content_encoding')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('push_subscriptions');
    }
};
