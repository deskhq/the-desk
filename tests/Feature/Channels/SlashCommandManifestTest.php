<?php

use App\Actions\Teams\CreateTeam;
use App\Models\Channel;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('a channel page ships the slash-command manifest for autocomplete', function (): void {
    $owner = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'Acme');

    $this->actingAs($owner)
        ->get(route('channels.show', ['team' => $team->slug, 'channel' => Channel::GENERAL_SLUG]))
        ->assertInertia(fn (Assert $page): Assert => $page
            ->has('slashCommands', 4)
            ->where('slashCommands.0.name', 'shrug')
            ->where('slashCommands.0.description', 'Append a shrug to your message')
            ->where('slashCommands.0.argumentHint', '[message]')
            ->where('slashCommands.1.name', 'tableflip')
            ->where('slashCommands.2.name', 'unflip')
            ->where('slashCommands.3.name', 'poll')
            ->where('slashCommands.3.description', 'Create a poll in this channel')
            ->where('slashCommands.3.argumentHint', null)
        );
});

test('the manifest copy follows the active locale', function (): void {
    $owner = User::factory()->create(['locale' => 'fr']);
    $team = app(CreateTeam::class)->handle($owner, 'Acme');

    $this->actingAs($owner)
        ->get(route('channels.show', ['team' => $team->slug, 'channel' => Channel::GENERAL_SLUG]))
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('slashCommands.1.description', 'Retourner la table')
        );
});

test('the manifest is omitted off the workspace', function (): void {
    $owner = User::factory()->create();
    app(CreateTeam::class)->handle($owner, 'Acme');

    $this->actingAs($owner)
        ->get(route('profile.edit'))
        ->assertInertia(fn (Assert $page): Assert => $page->where('slashCommands', []));
});
