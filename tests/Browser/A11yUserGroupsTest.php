<?php

declare(strict_types=1);

use App\Models\Message;
use App\Models\Team;
use App\Models\User;
use App\Models\UserGroup;

/**
 * Seed a workspace whose #general already carries a read group mention, so the
 * audit sees the rendered group pill rather than an empty timeline.
 *
 * @return array{owner: User, team: Team}
 */
function browserTeamWithGroupMention(): array
{
    ['owner' => $alice, 'member' => $bob, 'team' => $team, 'channel' => $channel] = browserTeamWithChannel();

    $group = UserGroup::factory()->for($team)->slug('dev-team')->create();
    $group->members()->attach($alice->id);

    $message = Message::factory()->create([
        'channel_id' => $channel->id,
        'user_id' => $bob->id,
        'body' => "heads up @[{$group->slug}](group:{$group->id})",
    ]);

    // Read, so the unread divider and jump pill (which carry contrast debt
    // tracked separately) stay out of this audit.
    $alice->channels()->updateExistingPivot($channel->id, [
        'last_read_message_id' => $message->id,
    ]);

    return ['owner' => $alice, 'team' => $team];
}

test('a rendered group mention pill passes the axe audit in either theme', function (): void {
    ['owner' => $alice] = browserTeamWithGroupMention();

    $page = signInThroughBrowser($alice)
        ->assertPresent('[data-test="message-group-mention"]')
        // Open the `@` menu on a query that matches the group, so the tagged
        // group row and its member count are audited inside the live listbox.
        ->type('@message-composer-input', '@dev')
        ->assertPresent('[data-test="mention-option-group-count"]')
        ->assertNoAccessibilityIssues();

    switchToDarkTheme($page)
        ->assertNoAccessibilityIssues();
});

test('the user groups settings page passes the axe audit in either theme', function (): void {
    ['owner' => $alice, 'team' => $team] = browserTeamWithGroupMention();

    $page = signInThroughBrowser($alice)
        ->navigate("/settings/teams/{$team->slug}/groups")
        ->assertSee('User groups')
        ->assertPresent('[data-test="group-row-dev-team"]')
        // The editor dialog holds the rename form, the member chips with their
        // icon-only remove buttons, and the candidate picker.
        ->click('@group-edit-dev-team')
        ->assertPresent('[data-test="group-edit-dialog"]')
        // The dialog fades and zooms in; auditing mid-transition samples the
        // interpolated (washed-out) colors rather than the settled tokens, so
        // let the animation finish before axe reads the DOM.
        ->wait(0.5)
        ->assertNoAccessibilityIssues();

    switchToDarkTheme($page)
        ->assertNoAccessibilityIssues();
});
