<?php

use App\Actions\Channels\OpenDirectMessage;
use App\Actions\Teams\CreateTeam;
use App\Data\ChannelData;
use App\Enums\TeamRole;
use App\Models\Channel;
use App\Models\User;

test('a standard channel has no direct participant', function () {
    $viewer = User::factory()->create();
    $channel = Channel::factory()->create();

    expect($channel->directParticipantFor($viewer))->toBeNull();
});

test('a direct channel renders the other participant viewer-relatively', function () {
    $owner = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'Acme');
    $other = User::factory()->create();
    $team->memberships()->create(['user_id' => $other->id, 'role' => TeamRole::Member]);

    $dm = app(OpenDirectMessage::class)->handle($team, $owner, $other);

    $this->actingAs($owner);
    $ownerView = ChannelData::fromChannel($dm->fresh());

    expect($ownerView->isDirect)->toBeTrue()
        ->and($ownerView->name)->toBe($other->name)
        ->and($ownerView->dmUserId)->toBe($other->id);

    $this->actingAs($other);
    $otherView = ChannelData::fromChannel($dm->fresh());

    expect($otherView->name)->toBe($owner->name)
        ->and($otherView->dmUserId)->toBe($owner->id);
});

test('a self direct channel resolves the viewer as the participant', function () {
    $owner = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'Acme');

    $dm = app(OpenDirectMessage::class)->handle($team, $owner, $owner);

    $this->actingAs($owner);
    $view = ChannelData::fromChannel($dm->fresh());

    expect($view->isDirect)->toBeTrue()
        ->and($view->name)->toBe($owner->name)
        ->and($view->dmUserId)->toBe($owner->id);
});
