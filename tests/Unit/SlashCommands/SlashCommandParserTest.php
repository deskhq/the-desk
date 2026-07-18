<?php

use App\SlashCommands\Commands\ShrugCommand;
use App\SlashCommands\SlashCommandParser;
use App\SlashCommands\SlashCommandRegistry;

function parserWithShrug(): SlashCommandParser
{
    $registry = new SlashCommandRegistry;
    $registry->register(new ShrugCommand);

    return new SlashCommandParser($registry);
}

test('it resolves a bare registered command with empty args', function (): void {
    $parsed = parserWithShrug()->parse('/shrug');

    expect($parsed)->not->toBeNull()
        ->and($parsed->command->name())->toBe('shrug')
        ->and($parsed->args)->toBe('');
});

test('it resolves a registered command and captures its trimmed args', function (): void {
    $parsed = parserWithShrug()->parse('/shrug   hello there  ');

    expect($parsed->command->name())->toBe('shrug')
        ->and($parsed->args)->toBe('hello there');
});

test('it does not intercept an unknown leading token', function (): void {
    expect(parserWithShrug()->parse('/foo bar'))->toBeNull();
});

test('it does not intercept a token that only prefixes a command name', function (): void {
    expect(parserWithShrug()->parse('/shrugging'))->toBeNull()
        ->and(parserWithShrug()->parse('/shrug-worthy idea'))->toBeNull();
});

test('it does not intercept a slash mid-path or after a leading space', function (): void {
    expect(parserWithShrug()->parse('/etc/hosts'))->toBeNull()
        ->and(parserWithShrug()->parse(' /shrug'))->toBeNull()
        ->and(parserWithShrug()->parse('please /shrug'))->toBeNull();
});

test('it does not intercept a lone slash', function (): void {
    expect(parserWithShrug()->parse('/'))->toBeNull();
});
