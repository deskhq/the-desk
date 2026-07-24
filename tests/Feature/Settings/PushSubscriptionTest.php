<?php

use App\Models\User;
use NotificationChannels\WebPush\PushSubscription;

beforeEach(function (): void {
    config([
        'webpush.vapid.public_key' => 'test-public-key',
        'webpush.vapid.private_key' => 'test-private-key',
    ]);
});

/**
 * The body a browser's `PushSubscription.toJSON()` produces.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function pushSubscriptionPayload(array $overrides = []): array
{
    return array_replace([
        'endpoint' => 'https://fcm.googleapis.com/fcm/send/device-one',
        'keys' => ['p256dh' => 'device-one-public-key', 'auth' => 'device-one-auth'],
    ], $overrides);
}

test('a device can subscribe itself to push notifications', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson(route('push-subscriptions.store'), pushSubscriptionPayload())
        ->assertNoContent();

    $subscription = $user->pushSubscriptions()->sole();

    expect($subscription->endpoint)->toBe('https://fcm.googleapis.com/fcm/send/device-one')
        ->and($subscription->public_key)->toBe('device-one-public-key')
        ->and($subscription->auth_token)->toBe('device-one-auth');
});

test('each device gets its own subscription row', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson(route('push-subscriptions.store'), pushSubscriptionPayload())
        ->assertNoContent();

    $this->actingAs($user)
        ->postJson(route('push-subscriptions.store'), pushSubscriptionPayload([
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/device-two',
            'keys' => ['p256dh' => 'device-two-public-key', 'auth' => 'device-two-auth'],
        ]))
        ->assertNoContent();

    expect($user->pushSubscriptions()->count())->toBe(2);
});

test('re-subscribing the same device refreshes its keys instead of duplicating it', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson(route('push-subscriptions.store'), pushSubscriptionPayload())
        ->assertNoContent();

    $this->actingAs($user)
        ->postJson(route('push-subscriptions.store'), pushSubscriptionPayload([
            'keys' => ['p256dh' => 'rotated-public-key', 'auth' => 'rotated-auth'],
        ]))
        ->assertNoContent();

    $subscription = $user->pushSubscriptions()->sole();

    expect($subscription->public_key)->toBe('rotated-public-key')
        ->and($subscription->auth_token)->toBe('rotated-auth');
});

test('a negotiated content encoding is stored with the subscription', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson(route('push-subscriptions.store'), pushSubscriptionPayload(['contentEncoding' => 'aesgcm']))
        ->assertNoContent();

    expect($user->pushSubscriptions()->sole()->content_encoding->value)->toBe('aesgcm');
});

test('signing in on a shared device takes the endpoint over from the previous account', function (): void {
    $previous = User::factory()->create();
    $next = User::factory()->create();

    $this->actingAs($previous)
        ->postJson(route('push-subscriptions.store'), pushSubscriptionPayload())
        ->assertNoContent();

    $this->actingAs($next)
        ->postJson(route('push-subscriptions.store'), pushSubscriptionPayload())
        ->assertNoContent();

    expect($previous->pushSubscriptions()->count())->toBe(0)
        ->and($next->pushSubscriptions()->count())->toBe(1);
});

test('a device can unsubscribe itself without touching the user other devices', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson(route('push-subscriptions.store'), pushSubscriptionPayload())
        ->assertNoContent();

    $this->actingAs($user)
        ->postJson(route('push-subscriptions.store'), pushSubscriptionPayload([
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/device-two',
        ]))
        ->assertNoContent();

    $this->actingAs($user)
        ->deleteJson(route('push-subscriptions.destroy'), ['endpoint' => 'https://fcm.googleapis.com/fcm/send/device-one'])
        ->assertNoContent();

    expect($user->pushSubscriptions()->pluck('endpoint')->all())
        ->toBe(['https://fcm.googleapis.com/fcm/send/device-two']);
});

test('unsubscribing an endpoint that is already gone is not an error', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->deleteJson(route('push-subscriptions.destroy'), ['endpoint' => 'https://fcm.googleapis.com/fcm/send/nothing'])
        ->assertNoContent();
});

test('a user cannot revoke another user subscription', function (): void {
    $owner = User::factory()->create();
    $stranger = User::factory()->create();

    $this->actingAs($owner)
        ->postJson(route('push-subscriptions.store'), pushSubscriptionPayload())
        ->assertNoContent();

    $this->actingAs($stranger)
        ->deleteJson(route('push-subscriptions.destroy'), ['endpoint' => 'https://fcm.googleapis.com/fcm/send/device-one'])
        ->assertNoContent();

    expect($owner->pushSubscriptions()->count())->toBe(1);
});

test('the subscription payload is validated', function (array $payload, string $field): void {
    $this->actingAs(User::factory()->create())
        ->postJson(route('push-subscriptions.store'), $payload)
        ->assertJsonValidationErrorFor($field);
})->with([
    'a missing endpoint' => [fn (): array => pushSubscriptionPayload(['endpoint' => null]), 'endpoint'],
    'a non-url endpoint' => [fn (): array => pushSubscriptionPayload(['endpoint' => 'not-a-url']), 'endpoint'],
    'an over-long endpoint' => [fn (): array => pushSubscriptionPayload(['endpoint' => 'https://push.example.test/'.str_repeat('x', 500)]), 'endpoint'],
    'a missing public key' => [fn (): array => pushSubscriptionPayload(['keys' => ['auth' => 'auth-only']]), 'keys.p256dh'],
    'a missing auth token' => [fn (): array => pushSubscriptionPayload(['keys' => ['p256dh' => 'key-only']]), 'keys.auth'],
    'an unknown content encoding' => [fn (): array => pushSubscriptionPayload(['contentEncoding' => 'rot13']), 'contentEncoding'],
]);

test('the revoke payload requires an endpoint', function (): void {
    $this->actingAs(User::factory()->create())
        ->deleteJson(route('push-subscriptions.destroy'), [])
        ->assertJsonValidationErrorFor('endpoint');
});

test('guests cannot manage push subscriptions', function (): void {
    $this->post(route('push-subscriptions.store'), pushSubscriptionPayload())
        ->assertRedirect(route('login'));
});

test('the subscription endpoints do not exist without a vapid keypair', function (): void {
    config(['webpush.vapid.public_key' => null, 'webpush.vapid.private_key' => null]);

    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson(route('push-subscriptions.store'), pushSubscriptionPayload())
        ->assertNotFound();

    $this->actingAs($user)
        ->deleteJson(route('push-subscriptions.destroy'), ['endpoint' => 'https://fcm.googleapis.com/fcm/send/device-one'])
        ->assertNotFound();
});

test('deleting an account takes its push subscriptions with it', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson(route('push-subscriptions.store'), pushSubscriptionPayload())
        ->assertNoContent();

    $this->actingAs($user)
        ->delete(route('profile.destroy'), ['password' => 'password'])
        ->assertRedirect();

    expect(PushSubscription::count())->toBe(0);
});
