<?php

declare(strict_types=1);

use App\Data\MessageSearchCriteria;
use App\Enums\MessageReminderStatus;
use App\Enums\NavDestination;
use App\Enums\SearchScope;
use App\Enums\TeamRole;
use App\Enums\ThreadInboxFilter;
use App\Models\ChannelSection;
use App\Models\User;
use App\Support\WorkspaceShell;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;

/*
|--------------------------------------------------------------------------
| The workspace shell (#1117)
|--------------------------------------------------------------------------
|
| The shell takes a viewer and a team, so every read-model it exposes is
| reachable without an HTTP round-trip — which is the whole reason it was
| lifted out of the middleware. This file was in `tests/Feature` and named no
| route in it; ADR-0012 puts it here.
|
| `teamMembers()` is stated here in full (who is listed, in what order, and
| who is withheld). `tests/Feature/Channels/TeamMembersPropTest.php` keeps the
| HTTP half: that a workspace render ships the prop, and that a page outside
| the workspace ships none. `channelSections()` is split the same way against
| `tests/Feature/Sidebar/ChannelSectionTest.php`, which keeps the endpoints.
|
*/

/**
 * A request standing on a named route, with the given bound parameters and
 * signed-in user. Built by hand so the precondition can be exercised without an
 * HTTP round-trip.
 *
 * @param  array<string, mixed>  $parameters
 */
function shellRequest(string $routeName, array $parameters = [], ?User $user = null, string $query = ''): Request
{
    $request = Request::create('/workspace'.($query === '' ? '' : '?'.$query));

    $route = new Route(['GET'], '/workspace', fn (): null => null);
    $route->name($routeName);
    $route->bind($request);

    foreach ($parameters as $key => $value) {
        $route->setParameter($key, $value);
    }

    $request->setRouteResolver(fn (): Route => $route);
    $request->setUserResolver(fn (): ?User => $user);

    return $request;
}

test('a signed-in viewer on a workspace route with a bound team gets a shell', function (): void {
    ['owner' => $viewer, 'team' => $team, 'channel' => $general] = teamWithChannel();

    $shell = WorkspaceShell::forRequest(shellRequest('channels.show', ['team' => $team], $viewer));

    expect($shell)->toBeInstanceOf(WorkspaceShell::class)
        ->and(array_column($shell->channels(null), 'slug'))->toBe([$general->slug]);
});

test('the precondition withholds a shell for a guest, off a workspace route, or with no bound team', function (): void {
    ['owner' => $viewer, 'team' => $team] = teamWithChannel();

    expect(WorkspaceShell::forRequest(shellRequest('channels.show', ['team' => $team])))->toBeNull()
        ->and(WorkspaceShell::forRequest(shellRequest('settings.profile', ['team' => $team], $viewer)))->toBeNull()
        ->and(WorkspaceShell::forRequest(shellRequest('channels.show', [], $viewer)))->toBeNull()
        // A team parameter that never resolved to a model is not a workspace either.
        ->and(WorkspaceShell::forRequest(shellRequest('channels.show', ['team' => 'acme'], $viewer)))->toBeNull();
});

test('the shell is constructible from a viewer and a team alone', function (): void {
    ['owner' => $viewer, 'team' => $team, 'channel' => $general] = teamWithChannel();

    $shell = new WorkspaceShell($viewer, $team);

    expect(array_column($shell->channels(null), 'slug'))->toBe([$general->slug])
        ->and(array_column($shell->teamMembers(), 'id'))->toBe([$viewer->id])
        ->and($shell->channelSections())->toBe([])
        ->and($shell->customEmojis())->toBe([])
        ->and($shell->userGroups())->toBe([])
        ->and($shell->reminders(MessageReminderStatus::Pending))->toBe([])
        ->and($shell->unreadDigest()->threads)->toBeFalse();
});

test('the team roster is ordered by name and lists the viewer among the others', function (): void {
    ['owner' => $viewer, 'team' => $team, 'channel' => $general] = teamWithChannel();
    $viewer->update(['name' => 'Marie Curie']);
    teamMemberInChannel($general, ['name' => 'Ada Lovelace']);
    teamMemberInChannel($general, ['name' => 'Zoe Zulu']);

    // The viewer is not held back: the people picker offers a self-DM, which
    // renders as "You" rather than as their name.
    expect(array_column(new WorkspaceShell($viewer, $team)->teamMembers(), 'name'))
        ->toBe(['Ada Lovelace', 'Marie Curie', 'Zoe Zulu']);
});

test('the team roster withholds a bot and another workspace entirely', function (): void {
    ['owner' => $viewer, 'team' => $team, 'channel' => $general] = teamWithChannel();

    // A bot posts in #general and is on the channel's roster, but it is not
    // someone you can open a DM with, so it is no part of this list.
    $bot = User::factory()->bot($team)->create(['name' => 'Deploy Bot']);
    channelMembership($general, $bot);

    // Nor is anyone from a workspace the viewer happens not to be looking at.
    teamWithChannel('Other');

    expect(array_column(new WorkspaceShell($viewer, $team)->teamMembers(), 'id'))->toBe([$viewer->id]);
});

test('the sidebar sections are the viewer own, in their manual order, scoped to the team', function (): void {
    ['owner' => $viewer, 'team' => $team, 'channel' => $general] = teamWithChannel();

    // Created out of order, so the manual position is what the order can come
    // from and not the insertion order.
    ChannelSection::factory()->for($viewer)->for($team)->position(1)->create(['name' => 'Second']);
    ChannelSection::factory()->for($viewer)->for($team)->position(0)->collapsed()->create(['name' => 'First']);

    // Another member's section in this team, and one of the viewer's own in a
    // workspace they are not looking at: neither belongs in this sidebar.
    ChannelSection::factory()->for(teamMemberInChannel($general))->for($team)->create(['name' => 'Theirs']);
    ['team' => $elsewhere] = teamWithChannel('Other');
    $elsewhere->memberships()->create(['user_id' => $viewer->id, 'role' => TeamRole::Member]);
    ChannelSection::factory()->for($viewer)->for($elsewhere)->create(['name' => 'Elsewhere']);

    $sections = new WorkspaceShell($viewer, $team)->channelSections();

    expect(array_column($sections, 'name'))->toBe(['First', 'Second'])
        ->and(array_column($sections, 'collapsed'))->toBe([true, false]);
});

test('a panel contributes its props only while its destination is the pinned one', function (): void {
    ['owner' => $viewer, 'team' => $team] = teamWithChannel();

    $shell = new WorkspaceShell($viewer, $team);
    $criteria = new MessageSearchCriteria(query: 'hello', scope: SearchScope::default());

    expect($shell->threadsPanelProps(NavDestination::Threads, ThreadInboxFilter::Unread))
        ->toHaveKeys(['threads', 'unreadThreadCount'])
        ->and($shell->threadsPanelProps(NavDestination::Search, ThreadInboxFilter::Unread))->toBe([])
        ->and($shell->threadsPanelProps(null, ThreadInboxFilter::Unread))->toBe([])
        ->and($shell->searchPanelProps(NavDestination::Search, $criteria))
        ->toHaveKeys(['searchCriteria', 'searchResults', 'searchWorkspaceChannels'])
        ->and($shell->searchPanelProps(NavDestination::Threads, $criteria))->toBe([])
        ->and($shell->searchPanelProps(null, $criteria))->toBe([]);
});
