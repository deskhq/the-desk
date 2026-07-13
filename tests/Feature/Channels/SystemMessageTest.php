<?php

use App\Actions\Channels\CreateChannel;
use App\Actions\Teams\CreateTeam;
use App\Data\MessageData;
use App\Enums\ChannelVisibility;
use App\Enums\MessageType;
use App\Enums\TeamRole;
use App\Models\Channel;
use App\Models\Message;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * A team owner in their #general channel, plus a system notice in that channel
 * authored by (recorded against) the owner.
 *
 * @return array{0: User, 1: Team, 2: Channel, 3: Message}
 */
function systemNoticeFixture(): array
{
    $owner = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'Acme');
    $general = Channel::where('team_id', $team->id)->where('slug', 'general')->firstOrFail();
    $notice = Message::factory()->for($general)->for($owner)->memberJoined()->create();

    return [$owner, $team, $general, $notice];
}

test('a system notice cannot be edited, even by its recorded author', function (): void {
    [$owner, $team, $general, $notice] = systemNoticeFixture();

    $this->actingAs($owner)
        ->patch(route('channels.messages.update', [
            'team' => $team->slug,
            'channel' => $general->slug,
            'message' => $notice->id,
        ]), ['body' => 'tampered'])
        ->assertForbidden();
});

test('a system notice cannot be deleted, even by a moderator', function (): void {
    [$owner, $team, $general, $notice] = systemNoticeFixture();

    $this->actingAs($owner)
        ->delete(route('channels.messages.destroy', [
            'team' => $team->slug,
            'channel' => $general->slug,
            'message' => $notice->id,
        ]))
        ->assertForbidden();

    expect($notice->fresh()->trashed())->toBeFalse();
});

test('a system notice cannot be reacted to', function (): void {
    [$owner, $team, $general, $notice] = systemNoticeFixture();

    $this->actingAs($owner)
        ->post(route('channels.messages.reactions.store', [
            'team' => $team->slug,
            'channel' => $general->slug,
            'message' => $notice->id,
        ]), ['emoji' => '👍'])
        ->assertForbidden();

    expect($notice->reactions()->count())->toBe(0);
});

test('a system notice cannot be forwarded', function (): void {
    [$owner, $team, $general, $notice] = systemNoticeFixture();
    $target = Channel::factory()->for($team)->create(['slug' => 'marketing']);
    $target->channelMembers()->create(['user_id' => $owner->id]);

    $this->actingAs($owner)
        ->post(route('channels.messages.forward', [
            'team' => $team->slug,
            'channel' => $general->slug,
            'message' => $notice->id,
        ]), [
            'client_uuid' => (string) Str::uuid7(),
            'target_channel_id' => $target->id,
        ])
        ->assertForbidden();
});

test('a message cannot inline-reply to a system notice', function (): void {
    [$owner, $team, $general, $notice] = systemNoticeFixture();

    $this->actingAs($owner)
        ->post(route('channels.messages.store', [
            'team' => $team->slug,
            'channel' => $general->slug,
        ]), [
            'body' => 'replying to a notice',
            'client_uuid' => (string) Str::uuid7(),
            'reply_to_id' => $notice->id,
        ])
        ->assertSessionHasErrors('reply_to_id');
});

test('a system notice cannot be the root of a thread reply', function (): void {
    [$owner, $team, $general, $notice] = systemNoticeFixture();

    $this->actingAs($owner)
        ->post(route('channels.messages.store', [
            'team' => $team->slug,
            'channel' => $general->slug,
        ]), [
            'body' => 'threading off a notice',
            'client_uuid' => (string) Str::uuid7(),
            'thread_root_id' => $notice->id,
        ])
        ->assertSessionHasErrors('thread_root_id');
});

test('MessageData exposes the message type', function (): void {
    [, , , $notice] = systemNoticeFixture();
    $notice->loadMessageDataRelations();

    expect(MessageData::fromMessage($notice)->type)->toBe(MessageType::MemberJoined);
});

test('system notices stay out of the search index', function (): void {
    [$owner, , $general, $notice] = systemNoticeFixture();
    $standard = Message::factory()->for($general)->for($owner)->create();

    expect($notice->shouldBeSearchable())->toBeFalse()
        ->and($standard->shouldBeSearchable())->toBeTrue();
});

test('creating a channel posts no join notice for its creator', function (): void {
    $user = User::factory()->create();
    $team = app(CreateTeam::class)->handle($user, 'Acme');

    $channel = app(CreateChannel::class)->handle($team, 'marketing', ChannelVisibility::Public, $user);

    expect($channel->messages()->count())->toBe(0);
});

test('joining a team posts no join notice in the onboarding #general channel', function (): void {
    $owner = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'Acme');
    $general = Channel::where('team_id', $team->id)->where('slug', 'general')->firstOrFail();

    $member = User::factory()->create();
    $team->memberships()->create(['user_id' => $member->id, 'role' => TeamRole::Member]);

    expect($general->messages()->count())->toBe(0);
});

test('isSystem reflects the message type', function (): void {
    expect(MessageType::Standard->isSystem())->toBeFalse()
        ->and(MessageType::MemberJoined->isSystem())->toBeTrue()
        ->and(MessageType::MemberLeft->isSystem())->toBeTrue();
});
