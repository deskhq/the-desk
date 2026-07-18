<?php

use App\SlashCommands\Commands\ShrugCommand;
use App\SlashCommands\Commands\TableflipCommand;
use App\SlashCommands\SlashCommandRegistry;

test('it registers, finds, and reports commands by name', function (): void {
    $registry = new SlashCommandRegistry;
    $shrug = new ShrugCommand;
    $registry->register($shrug);

    expect($registry->has('shrug'))->toBeTrue()
        ->and($registry->has('missing'))->toBeFalse()
        ->and($registry->find('shrug'))->toBe($shrug)
        ->and($registry->find('missing'))->toBeNull()
        ->and($registry->all())->toBe([$shrug]);
});

test('registering a second command under an existing name replaces it', function (): void {
    $registry = new SlashCommandRegistry;
    $first = new ShrugCommand;
    $second = new ShrugCommand;
    $registry->register($first);
    $registry->register($second);

    expect($registry->all())->toHaveCount(1)
        ->and($registry->find('shrug'))->toBe($second);
});

test('the manifest is one typed dto per command in registration order', function (): void {
    $registry = new SlashCommandRegistry;
    $registry->register(new ShrugCommand);
    $registry->register(new TableflipCommand);

    $manifest = $registry->manifest();

    expect($manifest)->toHaveCount(2)
        ->and($manifest[0]->name)->toBe('shrug')
        ->and($manifest[0]->description)->toBe('Append a shrug to your message')
        ->and($manifest[0]->argumentHint)->toBe('[message]')
        ->and($manifest[1]->name)->toBe('tableflip');
});
