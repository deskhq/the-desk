<?php

test('reverb allowed origins default to any origin when the env var is absent', function (): void {
    $this->reloadWithEnv(['REVERB_ALLOWED_ORIGINS' => '']);

    expect(config('reverb.apps.apps.0.allowed_origins'))->toBe(['*']);
});

test('reverb allowed origins can be locked to a single origin via env', function (): void {
    $this->reloadWithEnv(['REVERB_ALLOWED_ORIGINS' => 'chat.example.test']);

    expect(config('reverb.apps.apps.0.allowed_origins'))->toBe(['chat.example.test']);
});

test('reverb allowed origins accept a comma-separated list of origins', function (): void {
    $this->reloadWithEnv(['REVERB_ALLOWED_ORIGINS' => 'chat.example.test,ws.example.test']);

    expect(config('reverb.apps.apps.0.allowed_origins'))->toBe(['chat.example.test', 'ws.example.test']);
});
