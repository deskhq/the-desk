<?php

use App\Actions\Teams\CreateTeam;
use App\Data\MessageSearchCriteria;
use App\Enums\ChannelVisibility;
use App\Enums\MessageReminderStatus;
use App\Enums\NavDestination;
use App\Enums\SearchScope;
use App\Enums\ThreadInboxFilter;
use App\Models\Channel;
use App\Models\Team;
use App\Models\User;
use App\Support\WorkspaceShell;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;

/**
 * A viewer, their team, and its #general channel.
 *
 * @return array{0: User, 1: Team, 2: Channel}
 */
function shellWorkspace(): array
{
    $viewer = User::factory()->create();
    $team = app(CreateTeam::class)->handle($viewer, 'Acme');
    $general = Channel::where('team_id', $team->id)->where('slug', Channel::GENERAL_SLUG)->firstOrFail();

    return [$viewer, $team, $general];
}

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
    [$viewer, $team, $general] = shellWorkspace();

    $shell = WorkspaceShell::forRequest(shellRequest('channels.show', ['team' => $team], $viewer));

    expect($shell)->toBeInstanceOf(WorkspaceShell::class)
        ->and(array_column($shell->channels(null), 'slug'))->toBe([$general->slug]);
});

test('the precondition withholds a shell for a guest, off a workspace route, or with no bound team', function (): void {
    [$viewer, $team] = shellWorkspace();

    expect(WorkspaceShell::forRequest(shellRequest('channels.show', ['team' => $team])))->toBeNull()
        ->and(WorkspaceShell::forRequest(shellRequest('settings.profile', ['team' => $team], $viewer)))->toBeNull()
        ->and(WorkspaceShell::forRequest(shellRequest('channels.show', [], $viewer)))->toBeNull()
        // A team parameter that never resolved to a model is not a workspace either.
        ->and(WorkspaceShell::forRequest(shellRequest('channels.show', ['team' => 'acme'], $viewer)))->toBeNull();
});

test('the shell is constructible from a viewer and a team alone', function (): void {
    [$viewer, $team, $general] = shellWorkspace();

    $shell = new WorkspaceShell($viewer, $team);

    expect(array_column($shell->channels(null), 'slug'))->toBe([$general->slug])
        ->and(array_column($shell->teamMembers(), 'id'))->toBe([$viewer->id])
        ->and($shell->channelSections())->toBe([])
        ->and($shell->customEmojis())->toBe([])
        ->and($shell->userGroups())->toBe([])
        ->and($shell->reminders(MessageReminderStatus::Pending))->toBe([])
        ->and($shell->hasUnreadThreads())->toBeFalse()
        ->and($shell->slashCommands())->not->toBeEmpty()
        ->and($shell->creatableChannelVisibilities())->toContain(ChannelVisibility::Public->value);
});

test('a panel contributes its props only while its destination is the pinned one', function (): void {
    [$viewer, $team] = shellWorkspace();

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
