<?php

use Inertia\Testing\AssertableInertia as Assert;

test('the vapid public key is shared to the frontend when push is configured', function (): void {
    config(['webpush.vapid.public_key' => 'test-public-key', 'webpush.vapid.private_key' => 'test-private-key']);

    $this->get(route('home'))
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('webPush.enabled', true)
            ->where('webPush.publicKey', 'test-public-key')
        );
});

test('no push key reaches the frontend when the instance has no vapid keypair', function (): void {
    config(['webpush.vapid.public_key' => null, 'webpush.vapid.private_key' => null]);

    $this->get(route('home'))
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('webPush.enabled', false)
            ->where('webPush.publicKey', null)
        );
});
