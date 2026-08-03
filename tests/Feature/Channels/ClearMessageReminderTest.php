<?php

use App\Actions\Teams\CreateTeam;
use App\Models\Channel;
use App\Models\Message;
use App\Models\MessageReminder;
use App\Models\User;

test('a user can clear one of their reminders', function (): void {
    ['owner' => $owner, 'team' => $team, 'channel' => $general] = teamWithChannel();
    $message = Message::factory()->for($general)->for($owner)->create();
    $reminder = MessageReminder::factory()->for($owner)->for($message)->create();

    $this->actingAs($owner)
        ->delete(route('channels.reminders.destroy', ['team' => $team->slug, 'reminder' => $reminder->id]))
        ->assertRedirect();

    expect(MessageReminder::find($reminder->id))->toBeNull();
});

test('a user cannot clear someone else reminder', function (): void {
    ['owner' => $owner, 'team' => $team, 'channel' => $general] = teamWithChannel();
    $message = Message::factory()->for($general)->for($owner)->create();

    $other = User::factory()->create();
    $reminder = MessageReminder::factory()->for($other)->for($message)->create();

    $this->actingAs($owner)
        ->delete(route('channels.reminders.destroy', ['team' => $team->slug, 'reminder' => $reminder->id]))
        ->assertForbidden();

    expect(MessageReminder::find($reminder->id))->not->toBeNull();
});

test('a user can clear all their pending reminders in the team at once', function (): void {
    ['owner' => $owner, 'team' => $team, 'channel' => $general] = teamWithChannel();
    $message = Message::factory()->for($general)->for($owner)->create();
    $secondMessage = Message::factory()->for($general)->for($owner)->create();

    MessageReminder::factory()->for($owner)->for($message)->create();
    $fired = MessageReminder::factory()->for($owner)->for($secondMessage)->fired()->create();

    $this->actingAs($owner)
        ->delete(route('channels.reminders.clear', ['team' => $team->slug]))
        ->assertRedirect();

    // Only pending reminders are cleared; a fired nudge awaiting acknowledgement stays.
    expect(MessageReminder::count())->toBe(1)
        ->and(MessageReminder::first()->is($fired))->toBeTrue();
});

test('clearing all leaves another team reminders untouched', function (): void {
    ['owner' => $owner, 'team' => $team, 'channel' => $general] = teamWithChannel();
    $message = Message::factory()->for($general)->for($owner)->create();
    MessageReminder::factory()->for($owner)->for($message)->create();

    // The same person's reminder in another workspace of theirs.
    $otherTeam = app(CreateTeam::class)->handle($owner, 'Beta');
    $otherGeneral = Channel::query()->where('team_id', $otherTeam->id)->where('slug', Channel::GENERAL_SLUG)->firstOrFail();
    $otherMessage = Message::factory()->for($otherGeneral)->for($owner)->create();
    $kept = MessageReminder::factory()->for($owner)->for($otherMessage)->create();

    $this->actingAs($owner)
        ->delete(route('channels.reminders.clear', ['team' => $team->slug]));

    expect(MessageReminder::count())->toBe(1)
        ->and(MessageReminder::first()->is($kept))->toBeTrue();
});
