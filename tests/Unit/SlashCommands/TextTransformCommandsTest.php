<?php

use App\Models\Channel;
use App\Models\Team;
use App\Models\User;
use App\SlashCommands\BaseSlashCommand;
use App\SlashCommands\Commands\ShrugCommand;
use App\SlashCommands\Commands\TableflipCommand;
use App\SlashCommands\Commands\UnflipCommand;
use App\SlashCommands\SlashCommand;
use App\SlashCommands\SlashCommandContext;
use App\SlashCommands\SlashCommandResult;

function contextWithArgs(string $args): SlashCommandContext
{
    return new SlashCommandContext(
        user: new User,
        team: new Team,
        channel: new Channel,
        threadRootId: null,
        args: $args,
        clientUuid: 'uuid',
    );
}

test('a text-transform command appends its glyph to leading text', function (string $class, string $glyph): void {
    /** @var SlashCommand $command */
    $command = new $class;

    expect($command->handle(contextWithArgs('hi'))->text)->toBe("hi {$glyph}")
        ->and($command->handle(contextWithArgs('  '))->text)->toBe($glyph)
        ->and($command->handle(contextWithArgs(''))->text)->toBe($glyph)
        ->and($command->handle(contextWithArgs('hi'))->isPostMessage())->toBeTrue();
})->with([
    'shrug' => [ShrugCommand::class, '¯\_(ツ)_/¯'],
    'tableflip' => [TableflipCommand::class, '(╯°□°)╯︵ ┻━┻'],
    'unflip' => [UnflipCommand::class, '┬─┬ ノ( ゜-゜ノ)'],
]);

test('the base command defaults to no argument hint', function (): void {
    $command = new class extends BaseSlashCommand
    {
        public function name(): string
        {
            return 'bare';
        }

        public function description(): string
        {
            return 'Bare';
        }

        public function handle(SlashCommandContext $ctx): SlashCommandResult
        {
            return SlashCommandResult::notice('ok');
        }
    };

    expect($command->argumentHint())->toBeNull();
});

test('a text-transform command is unrestricted and describes itself', function (): void {
    $command = new ShrugCommand;

    expect($command->name())->toBe('shrug')
        ->and($command->description())->toBe('Append a shrug to your message')
        ->and($command->argumentHint())->toBe('[message]')
        ->and($command->authorize(contextWithArgs('')))->toBeTrue();
});
