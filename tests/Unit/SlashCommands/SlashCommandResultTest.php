<?php

declare(strict_types=1);

use App\SlashCommands\SlashCommandResult;
use App\SlashCommands\SlashCommandResultType;

test('a postMessage result carries the body and reports its type', function (): void {
    $result = SlashCommandResult::postMessage('hello');

    expect($result->type)->toBe(SlashCommandResultType::PostMessage)
        ->and($result->text)->toBe('hello')
        ->and($result->isPostMessage())->toBeTrue()
        ->and($result->isNotice())->toBeFalse()
        ->and($result->isError())->toBeFalse();
});

test('a notice result reports its type', function (): void {
    $result = SlashCommandResult::notice('heads up');

    expect($result->isNotice())->toBeTrue()
        ->and($result->isPostMessage())->toBeFalse()
        ->and($result->isError())->toBeFalse();
});

test('an error result reports its type', function (): void {
    $result = SlashCommandResult::error('nope');

    expect($result->isError())->toBeTrue()
        ->and($result->isPostMessage())->toBeFalse()
        ->and($result->isNotice())->toBeFalse();
});
