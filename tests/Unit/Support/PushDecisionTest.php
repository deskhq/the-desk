<?php

declare(strict_types=1);

use App\Enums\NotificationLevel;
use App\Support\PushDecision;

/**
 * The default case: ordinary channel traffic reaching an unmuted member at the
 * "all" level, with no do-not-disturb running. Each test below flips exactly one
 * input away from it.
 *
 * @param  array<string, mixed>  $overrides
 */
function pushDecision(array $overrides = []): bool
{
    return PushDecision::shouldPush(...array_replace([
        'isOwnMessage' => false,
        'isChannelMessage' => true,
        'mentionsRecipient' => false,
        'muted' => false,
        'level' => NotificationLevel::All,
        'dndActive' => false,
    ], $overrides));
}

test('ordinary traffic pushes at the all level', function (): void {
    expect(pushDecision())->toBeTrue();
});

test('the author is never pushed about their own message', function (): void {
    expect(pushDecision(['isOwnMessage' => true]))->toBeFalse();
});

test('a muted channel never pushes, not even a mention', function (): void {
    expect(pushDecision(['muted' => true]))->toBeFalse()
        ->and(pushDecision(['muted' => true, 'mentionsRecipient' => true]))->toBeFalse();
});

test('do-not-disturb suppresses everything, mentions included', function (): void {
    expect(pushDecision(['dndActive' => true]))->toBeFalse()
        ->and(pushDecision(['dndActive' => true, 'mentionsRecipient' => true]))->toBeFalse();
});

test('ordinary traffic stays silent at the mentions level', function (): void {
    expect(pushDecision(['level' => NotificationLevel::Mentions]))->toBeFalse();
});

test('a mention pushes at the mentions level', function (): void {
    expect(pushDecision(['level' => NotificationLevel::Mentions, 'mentionsRecipient' => true]))->toBeTrue();
});

test('the nothing level silences ordinary traffic and mentions alike', function (): void {
    expect(pushDecision(['level' => NotificationLevel::Nothing]))->toBeFalse()
        ->and(pushDecision(['level' => NotificationLevel::Nothing, 'mentionsRecipient' => true]))->toBeFalse();
});

test('a thread-only reply does not push as ordinary traffic', function (): void {
    expect(pushDecision(['isChannelMessage' => false]))->toBeFalse();
});

test('a thread-only reply still pushes when it mentions the recipient', function (): void {
    expect(pushDecision(['isChannelMessage' => false, 'mentionsRecipient' => true]))->toBeTrue();
});
