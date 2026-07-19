<?php

use App\Models\Channel;
use App\Models\Team;
use App\Models\User;
use App\Providers\SlashCommandServiceProvider;
use App\SlashCommands\Commands\PollCommand;
use App\SlashCommands\SlashCommandContext;
use App\SlashCommands\SlashCommandRegistry;

function pollContext(): SlashCommandContext
{
    return new SlashCommandContext(
        user: new User,
        team: new Team,
        channel: new Channel,
        threadRootId: null,
        args: '',
        clientUuid: 'uuid',
    );
}

test('the /poll command advertises itself for autocomplete', function (): void {
    $command = new PollCommand;

    expect($command->name())->toBe('poll')
        ->and($command->description())->toBe('Create a poll in this channel')
        ->and($command->argumentHint())->toBeNull();
});

test('submitting /poll as raw text points the user to the builder', function (): void {
    $result = (new PollCommand)->handle(pollContext());

    expect($result->isError())->toBeTrue()
        ->and($result->text)->toBe('Use the poll builder to create a poll.');
});

test('the /poll command is registered only when polls are enabled', function (): void {
    config()->set('polls.enabled', true);
    $enabled = new SlashCommandRegistry;
    app()->instance(SlashCommandRegistry::class, $enabled);
    (new SlashCommandServiceProvider(app()))->boot();

    expect($enabled->has('poll'))->toBeTrue();

    config()->set('polls.enabled', false);
    $disabled = new SlashCommandRegistry;
    app()->instance(SlashCommandRegistry::class, $disabled);
    (new SlashCommandServiceProvider(app()))->boot();

    expect($disabled->has('poll'))->toBeFalse()
        // The built-in text commands are always present regardless.
        ->and($disabled->has('shrug'))->toBeTrue();
});
