<?php

use App\Models\Channel;
use App\SlashCommands\SlashCommandRegistry;
use Inertia\Testing\AssertableInertia as Assert;

/*
|--------------------------------------------------------------------------
| What is left here on purpose (#1117)
|--------------------------------------------------------------------------
|
| The manifest's contents — which commands exist, their copy, their argument
| hints, their order — are the registry's, and are asserted against it in
| `tests/Unit/SlashCommands/SlashCommandRegistryTest.php`. Rendering a channel
| page to read them back was proving the registry through the widest interface
| in the application.
|
| These two are not about the contents. The first is that `share()` hands the
| client every registered command with its copy resolved under the *request's*
| locale rather than the process's — middleware behaviour the registry cannot
| state, since it is handed an already-active translator. The second is the
| `$shell?->slashCommands() ?? []` fallback off the workspace, which is the
| Inertia contract for a page with no channel.
|
*/

test('a channel page ships every registered command, translated under the request locale', function (): void {
    ['owner' => $owner, 'team' => $team] = teamWithChannel();
    $owner->update(['locale' => 'fr']);

    $this->actingAs($owner)
        ->get(route('channels.show', ['team' => $team->slug, 'channel' => Channel::GENERAL_SLUG]))
        ->assertInertia(fn (Assert $page): Assert => $page
            // As many as are registered, not a hardcoded four: the count is the
            // claim that the page ships all of them, and the registry owns which.
            ->has('slashCommands', count(app(SlashCommandRegistry::class)->manifest()))
            ->where('slashCommands.1.description', 'Retourner la table')
        );
});

test('the manifest is omitted off the workspace', function (): void {
    ['owner' => $owner] = teamWithChannel();

    $this->actingAs($owner)
        ->get(route('profile.edit'))
        ->assertInertia(fn (Assert $page): Assert => $page->where('slashCommands', []));
});
