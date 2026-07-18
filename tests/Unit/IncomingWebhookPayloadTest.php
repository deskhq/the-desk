<?php

declare(strict_types=1);

use App\Support\Integrations\IncomingWebhookPayload;

it('reads the native body field', function (): void {
    expect(IncomingWebhookPayload::body(['body' => '  hello  ']))->toBe('hello');
});

it('falls back to the Slack text field', function (): void {
    expect(IncomingWebhookPayload::body(['text' => 'from slack']))->toBe('from slack');
});

it('prefers body over text when both are present', function (): void {
    expect(IncomingWebhookPayload::body(['body' => 'native', 'text' => 'slack']))->toBe('native');
});

it('ignores a blank body and falls through to text', function (): void {
    expect(IncomingWebhookPayload::body(['body' => '   ', 'text' => 'slack']))->toBe('slack');
});

it('returns null when neither field carries text', function (): void {
    expect(IncomingWebhookPayload::body(['blocks' => [['type' => 'section']]]))->toBeNull();
});

it('ignores non-string field values', function (): void {
    expect(IncomingWebhookPayload::body(['body' => ['nested'], 'text' => 42]))->toBeNull();
});
