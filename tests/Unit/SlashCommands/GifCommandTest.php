<?php

use App\Models\Channel;
use App\Models\Team;
use App\Models\User;
use App\Providers\SlashCommandServiceProvider;
use App\SlashCommands\Commands\GifCommand;
use App\SlashCommands\SlashCommandContext;
use App\SlashCommands\SlashCommandRegistry;

function gifContext(string $args = 'cats'): SlashCommandContext
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

test('the /gif command advertises itself for autocomplete', function (): void {
    $command = new GifCommand;

    expect($command->name())->toBe('gif')
        ->and($command->description())->toBe('Search Giphy for a GIF to send')
        ->and($command->argumentHint())->toBe('[search]');
});

test('submitting /gif as raw text points the user to the picker', function (): void {
    $result = (new GifCommand)->handle(gifContext());

    expect($result->isError())->toBeTrue()
        ->and($result->text)->toBe('Pick a GIF from the picker to send one.');
});

test('the /gif command is registered only when Giphy is configured', function (): void {
    config()->set('services.giphy.key', 'test-key');
    $enabled = new SlashCommandRegistry;
    app()->instance(SlashCommandRegistry::class, $enabled);
    (new SlashCommandServiceProvider(app()))->boot();

    expect($enabled->has('gif'))->toBeTrue();

    config()->set('services.giphy.key');
    $disabled = new SlashCommandRegistry;
    app()->instance(SlashCommandRegistry::class, $disabled);
    (new SlashCommandServiceProvider(app()))->boot();

    expect($disabled->has('gif'))->toBeFalse()
        // The built-in text commands are always present regardless.
        ->and($disabled->has('shrug'))->toBeTrue();
});
