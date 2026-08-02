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

it('reads the Slack identity-override fields', function (): void {
    expect(IncomingWebhookPayload::authorOverride([
        'username' => '  Release Train  ',
        'icon_url' => '  https://cdn.example.test/train.png  ',
    ]))->toBe([
        'username' => 'Release Train',
        'icon_url' => 'https://cdn.example.test/train.png',
    ]);
});

it('reads an identity override with no fields as absent', function (): void {
    expect(IncomingWebhookPayload::authorOverride(['text' => 'hi']))
        ->toBe(['username' => null, 'icon_url' => null]);
});

it('reads a blank identity-override field as absent', function (string $blank): void {
    expect(IncomingWebhookPayload::authorOverride(['username' => $blank, 'icon_url' => $blank]))
        ->toBe(['username' => null, 'icon_url' => null]);
})->with([
    'empty' => [''],
    'whitespace' => ["  \n\t "],
]);

it('passes a non-string identity-override field through for validation to reject', function (): void {
    expect(IncomingWebhookPayload::authorOverride(['username' => 42, 'icon_url' => ['nested']]))
        ->toBe(['username' => 42, 'icon_url' => ['nested']]);
});

it('ignores the Slack icon_emoji field entirely', function (): void {
    expect(IncomingWebhookPayload::authorOverride(['icon_emoji' => ':rocket:']))
        ->toBe(['username' => null, 'icon_url' => null]);
});
