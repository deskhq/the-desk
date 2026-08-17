<?php

declare(strict_types=1);

use App\Enums\ChannelVisibility;
use App\Models\Channel;
use App\Models\ChannelMember;
use App\Models\User;

test('a channel belongs to its creator', function (): void {
    $creator = User::factory()->create();
    $channel = Channel::factory()->create(['created_by' => $creator->id]);

    expect($channel->creator)->not->toBeNull()
        ->and($channel->creator->is($creator))->toBeTrue();
});

test('a channel member belongs to a channel and a user', function (): void {
    $channel = Channel::factory()->create();
    $user = User::factory()->create();
    $member = ChannelMember::factory()->create([
        'channel_id' => $channel->id,
        'user_id' => $user->id,
    ]);

    expect($member->channel->is($channel))->toBeTrue()
        ->and($member->user->is($user))->toBeTrue();
});

test('a standard channel has no direct participant to resolve', function (): void {
    $viewer = User::factory()->create();
    $channel = Channel::factory()->create();

    expect($channel->directParticipantFor($viewer))->toBeNull();
});

test('channel visibility exposes a human label', function (): void {
    expect(ChannelVisibility::Public->label())->toBe('Public')
        ->and(ChannelVisibility::Private->label())->toBe('Private');
});

test('a channel cannot be saved with a blank slug', function (?string $slug): void {
    $channel = Channel::factory()->create(['name' => '日本語', 'slug' => $slug]);

    expect(trim($channel->fresh()->slug))->not->toBe('');
})->with([
    'empty' => [''],
    'whitespace' => ['   '],
    'null' => [null],
]);

test('a channel whose slug is blanked later has it regenerated on save', function (): void {
    $channel = Channel::factory()->create(['name' => 'Marketing', 'slug' => 'marketing']);

    $channel->slug = '';
    $channel->save();

    expect($channel->fresh()->slug)->toBe('marketing');
});
