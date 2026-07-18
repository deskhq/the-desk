<?php

declare(strict_types=1);

use App\Rules\PublicWebhookUrl;
use App\Support\Webhooks\WebhookUrlGuard;
use Illuminate\Support\Facades\Validator;

it('accepts a public https URL', function (): void {
    expect(WebhookUrlGuard::isPublic('https://example.test/hooks'))->toBeTrue();
    expect(WebhookUrlGuard::isPublic('http://example.test/hooks'))->toBeTrue();
});

it('rejects non-http schemes', function (): void {
    expect(WebhookUrlGuard::isPublic('ftp://example.test/x'))->toBeFalse();
    expect(WebhookUrlGuard::isPublic('file:///etc/passwd'))->toBeFalse();
});

it('rejects an unparseable URL or one missing a host', function (): void {
    expect(WebhookUrlGuard::isPublic('http://:80'))->toBeFalse();
    expect(WebhookUrlGuard::isPublic('not a url'))->toBeFalse();
});

it('rejects literal private, loopback, link-local, and metadata IPs', function (string $url): void {
    expect(WebhookUrlGuard::isPublic($url))->toBeFalse();
})->with([
    'loopback' => 'http://127.0.0.1/x',
    'private-10' => 'http://10.0.0.5/x',
    'private-192' => 'https://192.168.1.1/x',
    'link-local' => 'http://169.254.169.254/latest/meta-data',
    'ipv6-loopback' => 'http://[::1]/x',
]);

it('accepts a literal public IP', function (): void {
    expect(WebhookUrlGuard::isPublic('https://8.8.8.8/x'))->toBeTrue();
});

it('rejects local hostnames without a DNS lookup', function (string $url): void {
    expect(WebhookUrlGuard::isPublic($url))->toBeFalse();
})->with([
    'localhost' => 'http://localhost/x',
    'dot-localhost' => 'http://api.localhost/x',
    'dot-local' => 'http://printer.local/x',
    'dot-internal' => 'https://db.internal/x',
    'trailing-dot' => 'http://localhost./x',
]);

it('passes any URL when the guard is disabled', function (): void {
    config(['integrations.webhooks.block_private_urls' => false]);

    expect(WebhookUrlGuard::isPublic('http://127.0.0.1/x'))->toBeTrue();
});

it('drives the PublicWebhookUrl validation rule', function (): void {
    $passes = Validator::make(['url' => 'https://example.test/x'], ['url' => new PublicWebhookUrl]);
    expect($passes->passes())->toBeTrue();

    $fails = Validator::make(['url' => 'http://169.254.169.254/'], ['url' => new PublicWebhookUrl]);
    expect($fails->fails())->toBeTrue()
        ->and($fails->errors()->first('url'))->toBe('The webhook URL must be a public HTTP or HTTPS address.');
});

it('ignores a non-string value in the rule, leaving type validation to other rules', function (): void {
    $validator = Validator::make(['url' => ['array']], ['url' => new PublicWebhookUrl]);

    expect($validator->passes())->toBeTrue();
});
