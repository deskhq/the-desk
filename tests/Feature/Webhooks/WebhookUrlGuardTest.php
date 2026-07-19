<?php

declare(strict_types=1);

use App\Rules\PublicWebhookUrl;
use App\Support\HostResolver;
use App\Support\Webhooks\WebhookUrlGuard;
use Illuminate\Support\Facades\Validator;

/**
 * Build a guard whose resolver returns the given IPs for every hostname, so
 * delivery-time resolution can be exercised without touching real DNS.
 *
 * @param  array<int, string>  $ips
 */
function guardResolving(array $ips): WebhookUrlGuard
{
    return new WebhookUrlGuard(new class($ips) extends HostResolver
    {
        /**
         * @param  array<int, string>  $ips
         */
        public function __construct(private readonly array $ips) {}

        public function resolve(string $host): array
        {
            return $this->ips;
        }
    });
}

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

it('resolves a hostname to its vetted public IP for delivery pinning', function (): void {
    expect(guardResolving(['93.184.216.34', '93.184.216.35'])->resolveDeliveryIp('https://example.test/hook'))
        ->toBe('93.184.216.34');
});

it('blocks delivery when any resolved address is non-public', function (string $ip): void {
    expect(guardResolving(['93.184.216.34', $ip])->resolveDeliveryIp('https://example.test/hook'))
        ->toBeFalse();
})->with([
    'private' => '10.0.0.5',
    'loopback' => '127.0.0.1',
    'metadata' => '169.254.169.254',
    'ipv6-loopback' => '::1',
]);

it('blocks delivery when the hostname does not resolve', function (): void {
    expect(guardResolving([])->resolveDeliveryIp('https://example.test/hook'))->toBeFalse();
});

it('blocks delivery for a URL with no host', function (): void {
    expect(guardResolving(['93.184.216.34'])->resolveDeliveryIp('http://'))->toBeFalse();
});

it('does not pin a literal-IP URL, which the literal guard already vets', function (): void {
    expect(guardResolving(['10.0.0.5'])->resolveDeliveryIp('https://8.8.8.8/hook'))->toBeNull();
});

it('skips delivery-time resolution when the guard is disabled', function (): void {
    config(['integrations.webhooks.block_private_urls' => false]);

    expect(guardResolving(['10.0.0.5'])->resolveDeliveryIp('https://example.test/hook'))->toBeNull();
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
