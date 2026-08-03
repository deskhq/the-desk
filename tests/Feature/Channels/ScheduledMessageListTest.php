<?php

use App\Enums\TeamRole;
use App\Models\Channel;
use App\Models\Message;
use App\Models\ScheduledMessage;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('the channel page lists the viewer own pending scheduled messages, soonest first', function (): void {
    ['owner' => $owner, 'team' => $team, 'channel' => $general] = teamWithChannel();

    $later = ScheduledMessage::factory()->for($general)->for($owner)->create([
        'body' => 'the later one',
        'send_at' => now()->addHours(3),
    ]);
    $sooner = ScheduledMessage::factory()->for($general)->for($owner)->create([
        'body' => 'the sooner one',
        'send_at' => now()->addHour(),
    ]);

    $this->actingAs($owner)
        ->get(route('channels.show', ['team' => $team->slug, 'channel' => $general->slug]))
        ->assertInertia(fn (Assert $page): Assert => $page
            ->has('scheduledMessages', 2)
            ->where('scheduledMessages.0.id', $sooner->id)
            ->where('scheduledMessages.0.body', 'the sooner one')
            ->where('scheduledMessages.1.id', $later->id)
        );
});

test('each row carries the client uuid the composer minted, so the client can point at the row it just created', function (): void {
    ['owner' => $owner, 'team' => $team, 'channel' => $general] = teamWithChannel();

    $scheduled = ScheduledMessage::factory()->for($general)->for($owner)->create([
        'client_uuid' => '019fa561-9fc6-73d7-9166-05358afb47c9',
    ]);

    $this->actingAs($owner)
        ->get(route('channels.show', ['team' => $team->slug, 'channel' => $general->slug]))
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('scheduledMessages.0.id', $scheduled->id)
            ->where('scheduledMessages.0.clientUuid', '019fa561-9fc6-73d7-9166-05358afb47c9')
        );
});

test('the list excludes other members scheduled messages', function (): void {
    ['owner' => $owner, 'team' => $team, 'channel' => $general] = teamWithChannel();

    $other = User::factory()->create();
    $team->memberships()->create(['user_id' => $other->id, 'role' => TeamRole::Member]);

    ScheduledMessage::factory()->for($general)->for($owner)->create(['body' => 'mine']);
    ScheduledMessage::factory()->for($general)->for($other)->create(['body' => 'theirs']);

    $this->actingAs($owner)
        ->get(route('channels.show', ['team' => $team->slug, 'channel' => $general->slug]))
        ->assertInertia(fn (Assert $page): Assert => $page
            ->has('scheduledMessages', 1)
            ->where('scheduledMessages.0.body', 'mine')
        );
});

test('the list excludes sent and cancelled scheduled messages', function (): void {
    ['owner' => $owner, 'team' => $team, 'channel' => $general] = teamWithChannel();

    ScheduledMessage::factory()->for($general)->for($owner)->create(['body' => 'still pending']);
    ScheduledMessage::factory()->for($general)->for($owner)->sent()->create(['body' => 'already sent']);
    ScheduledMessage::factory()->for($general)->for($owner)->cancelled()->create(['body' => 'was cancelled']);

    $this->actingAs($owner)
        ->get(route('channels.show', ['team' => $team->slug, 'channel' => $general->slug]))
        ->assertInertia(fn (Assert $page): Assert => $page
            ->has('scheduledMessages', 1)
            ->where('scheduledMessages.0.body', 'still pending')
        );
});

test('the list is scoped to the current channel', function (): void {
    ['owner' => $owner, 'team' => $team, 'channel' => $general] = teamWithChannel();
    $other = Channel::factory()->for($team)->create();
    $other->channelMembers()->create(['user_id' => $owner->id]);

    ScheduledMessage::factory()->for($general)->for($owner)->create(['body' => 'in general']);
    ScheduledMessage::factory()->for($other)->for($owner)->create(['body' => 'in other']);

    $this->actingAs($owner)
        ->get(route('channels.show', ['team' => $team->slug, 'channel' => $general->slug]))
        ->assertInertia(fn (Assert $page): Assert => $page
            ->has('scheduledMessages', 1)
            ->where('scheduledMessages.0.body', 'in general')
        );
});

test('a scheduled message carries its inline reply quote', function (): void {
    ['owner' => $owner, 'team' => $team, 'channel' => $general] = teamWithChannel();
    $parent = Message::factory()->for($general)->for($owner)->create(['body' => 'the original']);
    ScheduledMessage::factory()->for($general)->for($owner)->replyTo($parent)->create(['body' => 'scheduled answer']);

    $this->actingAs($owner)
        ->get(route('channels.show', ['team' => $team->slug, 'channel' => $general->slug]))
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('scheduledMessages.0.replyTo.id', $parent->id)
            ->where('scheduledMessages.0.replyTo.body', 'the original')
            ->where('scheduledMessages.0.replyTo.authorName', $owner->name)
        );
});
