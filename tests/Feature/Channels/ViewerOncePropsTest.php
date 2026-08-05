<?php

use App\Enums\MessageReminderStatus;
use App\Models\ChannelSection;
use App\Models\Message;
use App\Models\MessageReminder;
use App\Models\User;

/*
|--------------------------------------------------------------------------
| The props the viewer's own writes invalidate (#1252)
|--------------------------------------------------------------------------
|
| The second group of once'd shell props, after the instance constants and
| session facts of #1251. These do change — but only when the viewer writes,
| and the client already names each of them in a partial reload after exactly
| that write. Since the once exclusion is bypassed on partial requests, those
| existing `only:` reloads *are* the invalidation: nothing new fires, the props
| simply stop riding along on the navigations in between.
|
| Two discriminators carry that, and both are about the same client property —
| a once prop is stored by key but restored by *prop path*, so a key that comes
| back round to a value the path no longer holds restores the wrong one:
|
| 1. **The viewer**, as a digest of the session's CSRF token, which Laravel
|    rotates on both sign-in and sign-out. Without it `auth` under a guest's key
|    would be excluded after a sign-out and the client would restore the account
|    that just left.
| 2. **The workspace**, for the four props whose answer is a team's. They are
|    absent off a workspace route rather than `[]`, which is what lets the
|    client drop their keys and ask again on the way back in.
|
| `auth` carries a third: a digest of its own payload, so that any write to the
| viewer's row reships it on the next navigation whether or not the surface that
| made the write remembered to name `AUTH_PROPS`.
|
*/

/**
 * The props keyed on the viewer alone, carried on every route.
 *
 * @var list<string>
 */
const VIEWER_ONCE_PROPS = [
    'auth',
    'collapsedChannelSections',
    'frequentEmojis',
];

/**
 * The props keyed on the viewer's workspace, carried only inside one.
 *
 * @var list<string>
 */
const WORKSPACE_ONCE_PROPS = [
    'channels',
    'channelSections',
    'reminders',
    'firedReminders',
];

test('a first load carries every prop the viewer own writes invalidate', function (): void {
    ['owner' => $viewer, 'team' => $team] = teamWithChannel();

    $page = visitWorkspaceAs($viewer, $team)->assertOk()->viewData('page');

    expect(array_keys($page['props']))
        ->toContain(...VIEWER_ONCE_PROPS)
        ->toContain(...WORKSPACE_ONCE_PROPS);
});

test('a second visit carries none of them', function (): void {
    ['owner' => $viewer, 'team' => $team] = teamWithChannel();

    $first = visitWorkspaceAs($viewer, $team)->assertOk();

    $props = array_keys((array) revisit($first, workspaceUrl($team))->assertOk()->json('props'));

    expect(array_intersect([...VIEWER_ONCE_PROPS, ...WORKSPACE_ONCE_PROPS], $props))->toBe([]);
});

test('a second visit still carries what an ordinary navigation owes', function (): void {
    ['owner' => $viewer, 'team' => $team] = teamWithChannel();

    $first = visitWorkspaceAs($viewer, $team)->assertOk();

    // What is left is the genuinely volatile half: what the viewer has not
    // read, and the roster whose presence dots move without any write of theirs.
    expect(array_keys((array) revisit($first, workspaceUrl($team))->assertOk()->json('props')))
        ->toContain('errors', 'unread', 'teamMembers');
});

test('a partial reload naming a set receives it', function (string $prop): void {
    ['owner' => $viewer, 'team' => $team] = teamWithChannel();

    $first = visitWorkspaceAs($viewer, $team)->assertOk();

    // The sixteen `only:` call sites in `resources/js/lib/reloadProps.ts` keep
    // working for exactly this reason, and it is the whole mechanism: the once
    // exclusion is gated on the request not being a partial one.
    expect(array_keys((array) partialRevisit($first, workspaceUrl($team), [$prop])->assertOk()->json('props')))
        ->toContain($prop);
})->with([...VIEWER_ONCE_PROPS, ...WORKSPACE_ONCE_PROPS]);

test('starring a channel brings the roster back with the star on it', function (): void {
    ['owner' => $viewer, 'team' => $team, 'channel' => $channel] = teamWithChannel();

    $first = visitWorkspaceAs($viewer, $team)->assertOk();

    $this->actingAs($viewer)
        ->patch(route('channels.star.update', ['team' => $team->slug, 'channel' => $channel->slug]), ['starred' => true])
        ->assertRedirect();

    // Both halves of the contract in one: the write is invisible to an ordinary
    // navigation, and the reload the star already fires (`CHANNEL_LIST_PROPS`)
    // is what carries it.
    expect(revisit($first, workspaceUrl($team))->assertOk()->json('props'))->not->toHaveKey('channels');

    expect(partialRevisit($first, workspaceUrl($team), ['channels'])->assertOk()->json('props.channels.0.starred'))
        ->toBeTrue();
});

test('collapsing a built-in group brings back the collapsed set', function (): void {
    ['owner' => $viewer, 'team' => $team] = teamWithChannel();

    $first = visitWorkspaceAs($viewer, $team)->assertOk();

    $viewer->update(['collapsed_channel_sections' => ['channels']]);

    expect(partialRevisit($first, workspaceUrl($team), ['collapsedChannelSections'])->assertOk()
        ->json('props.collapsedChannelSections'))
        ->toBe(['channels']);
});

test('reordering the custom sections brings back the section list', function (): void {
    ['owner' => $viewer, 'team' => $team] = teamWithChannel();

    $first = visitWorkspaceAs($viewer, $team)->assertOk();

    ChannelSection::factory()->for($viewer)->for($team)->create(['name' => 'Projects']);

    expect(partialRevisit($first, workspaceUrl($team), ['channelSections'])->assertOk()
        ->json('props.channelSections.0.name'))
        ->toBe('Projects');
});

test('a reminder coming due still reaches the client', function (): void {
    ['owner' => $viewer, 'team' => $team, 'channel' => $channel] = teamWithChannel();

    $first = visitWorkspaceAs($viewer, $team)->assertOk();

    // The dispatcher's `MessageReminderDue` broadcast reloads `REMINDER_PROPS`,
    // which is a partial request — so the nudge lands even though an ordinary
    // navigation no longer carries either half of the pair.
    $message = Message::factory()->for($channel)->for($viewer)->create();
    MessageReminder::factory()->for($viewer)->for($message)->create([
        'status' => MessageReminderStatus::Fired,
    ]);

    expect(partialRevisit($first, workspaceUrl($team), ['reminders', 'firedReminders'])->assertOk()
        ->json('props.firedReminders'))
        ->toHaveCount(1);
});

test('a write to the viewer own row reships auth without anyone naming it', function (): void {
    ['owner' => $viewer, 'team' => $team] = teamWithChannel();

    $first = visitWorkspaceAs($viewer, $team)->assertOk();

    // The key carries a digest of the prop's own payload, which is what makes
    // this correct for the writes that refresh `auth` from their redirect
    // rather than by naming `AUTH_PROPS` — and for a write nobody enumerated.
    $viewer->update(['name' => 'Renamed']);

    expect(revisit($first, workspaceUrl($team))->assertOk()->json('props.auth.user.name'))
        ->toBe('Renamed');
});

test('leaving the viewer own row alone keeps auth off the next visit', function (): void {
    ['owner' => $viewer, 'team' => $team] = teamWithChannel();

    $first = visitWorkspaceAs($viewer, $team)->assertOk();

    expect(revisit($first, workspaceUrl($team))->assertOk()->json('props'))->not->toHaveKey('auth');
});

test('signing out does not leave the client restoring the account that left', function (): void {
    ['owner' => $viewer, 'team' => $team] = teamWithChannel();

    $first = visitWorkspaceAs($viewer, $team)->assertOk();

    // Laravel rotates the session's token on both sign-in and sign-out, so the
    // viewer discriminator moves with them. Without it the guest's `auth` would
    // be excluded under a key the client already holds, and the client would
    // restore the signed-in user onto the page they just signed out of.
    $this->post(route('logout'))->assertRedirect();

    expect(revisit($first, route('login'))->assertOk()->json('props'))->toHaveKey('auth');
});

test('off a workspace route the workspace props are absent rather than empty', function (): void {
    $viewer = User::factory()->create();

    $props = (array) $this->actingAs($viewer)->get(route('profile.edit'))->assertOk()->viewData('page')['props'];

    // Absent, not `[]`: a once prop has one value per prop path for the life of
    // the page, so shipping an empty roster here would be the answer a later
    // workspace visit restored. Every consumer already defaults it.
    expect(array_intersect(WORKSPACE_ONCE_PROPS, array_keys($props)))->toBe([])
        ->and(array_keys($props))->toContain(...VIEWER_ONCE_PROPS);
});

test('the roster ships again on the way back into the workspace', function (): void {
    ['owner' => $viewer, 'team' => $team] = teamWithChannel();

    visitWorkspaceAs($viewer, $team)->assertOk();

    // The client only declares a once key while the prop path is defined, so a
    // settings excursion — which carries none of these — drops them and the way
    // back in is a first load again. Modelled here by revisiting from the
    // settings response, whose keys are exactly what the client would be left
    // holding.
    $settings = revisit(visitWorkspaceAs($viewer, $team), route('profile.edit'))->assertOk();

    expect(array_keys((array) revisit($settings, workspaceUrl($team))->assertOk()->json('props')))
        ->toContain(...WORKSPACE_ONCE_PROPS);
});

test('another workspace is another answer, not the one the client holds', function (): void {
    ['owner' => $viewer, 'team' => $team] = teamWithChannel();
    ['team' => $other, 'channel' => $otherGeneral] = teamWithChannel('Globex');
    joinTeamAndChannel($otherGeneral, $viewer);

    $first = visitWorkspaceAs($viewer, $team)->assertOk();

    // The workspace half of the key is what makes the first switch free: the
    // client has never declared the second team's key, so its roster ships
    // rather than being excluded under the first team's.
    expect(array_keys((array) revisit($first, workspaceUrl($other))->assertOk()->json('props')))
        ->toContain(...WORKSPACE_ONCE_PROPS);
});
