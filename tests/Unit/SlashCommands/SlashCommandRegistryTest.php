<?php

declare(strict_types=1);

use App\Data\SlashCommandData;
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

/**
 * What the composer's autocomplete actually receives, asserted where it is
 * decided (#1117). This was four `->where('slashCommands.N.…')` chains hung off
 * a rendered `channels/Show`, which booted the 44-prop shell and the whole
 * translation catalog to read a list the registry hands over on its own.
 *
 * The set is the always-on trio plus whatever the deployment's toggles admit —
 * `/gif` is out with no Giphy key and `/poll` is in with polls on, which is the
 * test environment's configuration. That each toggle governs its own command is
 * stated by {@see tests/Unit/SlashCommands/GifCommandTest.php} and
 * {@see tests/Unit/SlashCommands/PollCommandTest.php}; what is stated here is
 * the resulting manifest, in full, in the order the composer lists it.
 */
test('the registered manifest is the v1 command set, in registration order', function (): void {
    $manifest = array_map(
        fn (SlashCommandData $command): array => [$command->name, $command->description, $command->argumentHint],
        app(SlashCommandRegistry::class)->manifest(),
    );

    expect($manifest)->toBe([
        ['shrug', 'Append a shrug to your message', '[message]'],
        ['tableflip', 'Flip the table', '[message]'],
        ['unflip', 'Put the table back', '[message]'],
        ['poll', 'Create a poll in this channel', null],
    ]);
});
