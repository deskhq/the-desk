<?php

use App\Actions\Teams\CreateTeam;
use App\Data\UserTeam;
use App\Enums\MessageType;
use App\Enums\NotificationLevel;
use App\Enums\TeamRole;
use App\Models\Channel;
use App\Models\Message;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Collection;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * The workspace-switcher entry for the given team, as the viewer sees it.
 *
 * Read off `User::toUserTeams()`, which is what `share()` hands the `teams`
 * prop, rather than by rendering a page to pluck that prop back out (#1117).
 * The HTTP half — that a workspace render still ships it — is stated once, in
 * the last test in this file.
 *
 * @return array{unreadCount: int, mentionCount: int}
 */
function workspaceBadge(User $user, Team $team): array
{
    $entry = collect($user->toUserTeams(includeCurrent: true))->firstWhere('id', $team->id);

    expect($entry)->toBeInstanceOf(UserTeam::class);

    return ['unreadCount' => $entry->unreadCount, 'mentionCount' => $entry->mentionCount];
}

/**
 * Give the viewer a second workspace they share with an author, and return both
 * the workspace and its #general channel.
 *
 * @return array{0: Team, 1: Channel, 2: User}
 */
function otherWorkspace(User $viewer): array
{
    ['owner' => $author, 'team' => $team, 'channel' => $general] = teamWithChannel('Nord & Bureau');

    joinTeamAndChannel($general, $viewer);

    return [$team, $general, $author];
}

/** Post a message in the channel, optionally mentioning the viewer. */
function crossWorkspacePost(Channel $channel, User $author, ?User $mention = null, array $attributes = []): Message
{
    $message = Message::factory()->for($channel)->for($author)->create($attributes);

    if ($mention instanceof User) {
        $message->mentionedUsers()->attach($mention->id);
    }

    return $message;
}

test('a workspace the viewer is not reading carries its unread and mention counts', function (): void {
    $viewer = User::factory()->create();
    [$team, $general, $author] = otherWorkspace($viewer);

    crossWorkspacePost($general, $author);
    crossWorkspacePost($general, $author);
    crossWorkspacePost($general, $author, $viewer);

    expect(workspaceBadge($viewer, $team))
        ->toMatchArray(['unreadCount' => 3, 'mentionCount' => 1]);
});

test('a workspace with nothing new reports zero', function (): void {
    $viewer = User::factory()->create();
    [$team] = otherWorkspace($viewer);

    expect(workspaceBadge($viewer, $team))
        ->toMatchArray(['unreadCount' => 0, 'mentionCount' => 0]);
});

test('messages already read do not count', function (): void {
    $viewer = User::factory()->create();
    [$team, $general, $author] = otherWorkspace($viewer);

    crossWorkspacePost($general, $author);
    $last = crossWorkspacePost($general, $author);
    crossWorkspacePost($general, $author);

    $viewer->channels()->updateExistingPivot($general->id, ['last_read_message_id' => $last->id]);

    expect(workspaceBadge($viewer, $team)['unreadCount'])->toBe(1);
});

test('the viewer own messages and system notices never count', function (): void {
    $viewer = User::factory()->create();
    [$team, $general, $author] = otherWorkspace($viewer);

    crossWorkspacePost($general, $viewer);
    crossWorkspacePost($general, $author, attributes: ['type' => MessageType::MemberJoined]);

    expect(workspaceBadge($viewer, $team))
        ->toMatchArray(['unreadCount' => 0, 'mentionCount' => 0]);
});

test('a muted channel contributes neither unread nor mentions', function (): void {
    $viewer = User::factory()->create();
    [$team, $general, $author] = otherWorkspace($viewer);

    crossWorkspacePost($general, $author);
    crossWorkspacePost($general, $author, $viewer);

    $viewer->channels()->updateExistingPivot($general->id, ['muted' => true]);

    expect(workspaceBadge($viewer, $team))
        ->toMatchArray(['unreadCount' => 0, 'mentionCount' => 0]);
});

test('the mentions-only level keeps the mention count and silences ordinary unread', function (): void {
    $viewer = User::factory()->create();
    [$team, $general, $author] = otherWorkspace($viewer);

    crossWorkspacePost($general, $author);
    crossWorkspacePost($general, $author, $viewer);

    $viewer->channels()->updateExistingPivot($general->id, [
        'notification_level' => NotificationLevel::Mentions->value,
    ]);

    expect(workspaceBadge($viewer, $team))
        ->toMatchArray(['unreadCount' => 0, 'mentionCount' => 1]);
});

test('the nothing level silences the workspace entirely', function (): void {
    $viewer = User::factory()->create();
    [$team, $general, $author] = otherWorkspace($viewer);

    crossWorkspacePost($general, $author);
    crossWorkspacePost($general, $author, $viewer);

    $viewer->channels()->updateExistingPivot($general->id, [
        'notification_level' => NotificationLevel::Nothing->value,
    ]);

    expect(workspaceBadge($viewer, $team))
        ->toMatchArray(['unreadCount' => 0, 'mentionCount' => 0]);
});

test('a thread-only reply stays out of the unread count but its mention still counts', function (): void {
    $viewer = User::factory()->create();
    [$team, $general, $author] = otherWorkspace($viewer);

    $root = crossWorkspacePost($general, $author);
    crossWorkspacePost($general, $author, $viewer, [
        'thread_root_id' => $root->id,
        'sent_to_channel' => false,
    ]);

    expect(workspaceBadge($viewer, $team))
        ->toMatchArray(['unreadCount' => 1, 'mentionCount' => 1]);
});

test('a thread reply also sent to the channel counts like any other message', function (): void {
    $viewer = User::factory()->create();
    [$team, $general, $author] = otherWorkspace($viewer);

    $root = crossWorkspacePost($general, $author);
    crossWorkspacePost($general, $author, attributes: [
        'thread_root_id' => $root->id,
        'sent_to_channel' => true,
    ]);

    expect(workspaceBadge($viewer, $team)['unreadCount'])->toBe(2);
});

test('an archived channel drops out of the workspace counts', function (): void {
    $viewer = User::factory()->create();
    [$team, $general, $author] = otherWorkspace($viewer);

    crossWorkspacePost($general, $author);
    $general->update(['archived_at' => now()]);

    expect(workspaceBadge($viewer, $team)['unreadCount'])->toBe(0);
});

test('a deleted message stops counting', function (): void {
    $viewer = User::factory()->create();
    [$team, $general, $author] = otherWorkspace($viewer);

    crossWorkspacePost($general, $author)->delete();

    expect(workspaceBadge($viewer, $team)['unreadCount'])->toBe(0);
});

test('counts are grouped per workspace rather than pooled', function (): void {
    $viewer = User::factory()->create();
    [$first, $firstGeneral, $firstAuthor] = otherWorkspace($viewer);
    [$second, $secondGeneral, $secondAuthor] = otherWorkspace($viewer);

    crossWorkspacePost($firstGeneral, $firstAuthor);
    crossWorkspacePost($secondGeneral, $secondAuthor);
    crossWorkspacePost($secondGeneral, $secondAuthor, $viewer);

    expect(workspaceBadge($viewer, $first))->toMatchArray(['unreadCount' => 1, 'mentionCount' => 0]);
    expect(workspaceBadge($viewer, $second))->toMatchArray(['unreadCount' => 2, 'mentionCount' => 1]);
});

test('another member unread traffic never leaks into the viewer counts', function (): void {
    $viewer = User::factory()->create();
    [$team, $general, $author] = otherWorkspace($viewer);

    $bystander = User::factory()->create();
    $team->memberships()->create(['user_id' => $bystander->id, 'role' => TeamRole::Member]);
    $general->channelMembers()->firstOrCreate(['user_id' => $bystander->id]);

    crossWorkspacePost($general, $author, $bystander);

    expect(workspaceBadge($viewer, $team))
        ->toMatchArray(['unreadCount' => 1, 'mentionCount' => 0]);
});

test('a workspace render ships the switcher its badge counts', function (): void {
    ['owner' => $viewer, 'team' => $home, 'channel' => $homeGeneral] = teamWithChannel('Home');
    [$team, $general, $author] = otherWorkspace($viewer);

    crossWorkspacePost($general, $author, $viewer);

    $badge = workspaceBadge($viewer, $team);
    expect($badge)->toMatchArray(['unreadCount' => 1, 'mentionCount' => 1]);

    // The one HTTP claim the `teams` prop needs: a render still carries the
    // switcher, with the counts the read-model above is asserted on.
    $this->actingAs($viewer)
        ->get(route('channels.show', ['team' => $home->slug, 'channel' => $homeGeneral->slug]))
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('teams', fn (Collection $teams): bool => $teams->contains(
                fn (array $entry): bool => $entry['id'] === $team->id
                    && $entry['unreadCount'] === $badge['unreadCount']
                    && $entry['mentionCount'] === $badge['mentionCount']))
            ->etc());
});
