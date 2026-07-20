<?php

declare(strict_types=1);

use App\Support\Http\SecureSessionCookie;

test('a deployment served over https gets the Secure flag without being asked', function (string $appUrl): void {
    expect(SecureSessionCookie::defaultFor($appUrl))->toBeTrue();
})->with([
    'plain host' => ['https://desk.example.com'],
    'with a port' => ['https://desk.example.com:8443'],
    'shouted scheme' => ['HTTPS://desk.example.com'],
    'padded value' => ['  https://desk.example.com  '],
]);

test('a plain-http deployment keeps the flag off so its own cookies still arrive', function (?string $appUrl): void {
    expect(SecureSessionCookie::defaultFor($appUrl))->toBeFalse();
})->with([
    'http' => ['http://desk.example.test'],
    'no scheme' => ['desk.example.test'],
    'blank' => [''],
    'unset' => [null],
]);
