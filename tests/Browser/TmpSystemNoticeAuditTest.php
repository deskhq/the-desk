<?php

declare(strict_types=1);

use App\Enums\MessageType;
use App\Models\Message;

test('tmp: a pre-existing member notice in dark theme', function (): void {
    ['owner' => $alice, 'member' => $bob, 'channel' => $channel] = browserTeamWithChannel();

    Message::factory()->for($channel)->for($bob)->create([
        'body' => 'Launch coordination',
        'type' => MessageType::TopicChanged,
    ]);

    $page = signInThroughBrowser($alice)->assertPresent('@system-notice');

    switchToDarkTheme($page)->assertNoAccessibilityIssues();
});
