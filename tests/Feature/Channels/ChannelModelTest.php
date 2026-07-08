<?php

use App\Enums\ChannelVisibility;
use App\Models\Channel;
use App\Models\ChannelMember;
use App\Models\User;

test('a channel belongs to its creator', function () {
    $creator = User::factory()->create();
    $channel = Channel::factory()->create(['created_by' => $creator->id]);

    expect($channel->creator)->not->toBeNull()
        ->and($channel->creator->is($creator))->toBeTrue();
});

test('a channel member belongs to a channel and a user', function () {
    $channel = Channel::factory()->create();
    $user = User::factory()->create();
    $member = ChannelMember::factory()->create([
        'channel_id' => $channel->id,
        'user_id' => $user->id,
    ]);

    expect($member->channel->is($channel))->toBeTrue()
        ->and($member->user->is($user))->toBeTrue();
});

test('channel visibility exposes a human label', function () {
    expect(ChannelVisibility::Public->label())->toBe('Public')
        ->and(ChannelVisibility::Private->label())->toBe('Private');
});
