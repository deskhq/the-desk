<?php

declare(strict_types=1);

use App\Support\WebPushConfig;

test('web push is configured only when both vapid keys are set', function (): void {
    config(['webpush.vapid.public_key' => 'public', 'webpush.vapid.private_key' => 'private']);

    expect(WebPushConfig::configured())->toBeTrue();
});

test('web push is unconfigured when the public key is missing', function (): void {
    config(['webpush.vapid.public_key' => null, 'webpush.vapid.private_key' => 'private']);

    expect(WebPushConfig::configured())->toBeFalse();
});

test('web push is unconfigured when the private key is missing', function (): void {
    config(['webpush.vapid.public_key' => 'public', 'webpush.vapid.private_key' => '']);

    expect(WebPushConfig::configured())->toBeFalse();
});

test('the frontend payload carries the public key when configured', function (): void {
    config(['webpush.vapid.public_key' => 'public', 'webpush.vapid.private_key' => 'private']);

    expect(WebPushConfig::forFrontend())->toBe(['enabled' => true, 'publicKey' => 'public']);
});

test('the frontend payload withholds the public key when unconfigured', function (): void {
    config(['webpush.vapid.public_key' => 'public', 'webpush.vapid.private_key' => null]);

    expect(WebPushConfig::forFrontend())->toBe(['enabled' => false, 'publicKey' => null]);
});
