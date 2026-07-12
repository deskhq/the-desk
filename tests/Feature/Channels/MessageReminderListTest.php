<?php

use App\Actions\Teams\CreateTeam;
use App\Models\Channel;
use App\Models\Message;
use App\Models\MessageReminder;
use App\Models\Team;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Create a team with its owner already a member of #general.
 *
 * @return array{0: User, 1: Team, 2: Channel}
 */
function reminderListTeamWithGeneral(): array
{
    $owner = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'Acme');
    $general = Channel::where('team_id', $team->id)->where('slug', 'general')->firstOrFail();

    return [$owner, $team, $general];
}

test('the workspace shares the viewer pending reminders, soonest first', function (): void {
    [$owner, $team, $general] = reminderListTeamWithGeneral();

    $laterMessage = Message::factory()->for($general)->for($owner)->create(['body' => 'the later one']);
    $soonerMessage = Message::factory()->for($general)->for($owner)->create(['body' => 'the sooner one']);

    MessageReminder::factory()->for($owner)->for($laterMessage)->create(['remind_at' => now()->addHours(3)]);
    $sooner = MessageReminder::factory()->for($owner)->for($soonerMessage)->create(['remind_at' => now()->addHour()]);

    $this->actingAs($owner)
        ->get(route('channels.show', ['team' => $team->slug, 'channel' => $general->slug]))
        ->assertInertia(fn (Assert $page): Assert => $page
            ->has('reminders', 2)
            ->where('reminders.0.id', $sooner->id)
            ->where('reminders.0.messageId', $soonerMessage->id)
            ->where('reminders.0.body', 'the sooner one')
            ->where('reminders.0.channelSlug', 'general')
            ->where('reminders.0.teamSlug', $team->slug)
            ->where('reminders.0.authorName', $owner->name)
            ->where('reminders.1.body', 'the later one')
        );
});

test('fired reminders are shared separately from pending ones', function (): void {
    [$owner, $team, $general] = reminderListTeamWithGeneral();

    $pendingMessage = Message::factory()->for($general)->for($owner)->create(['body' => 'still pending']);
    $firedMessage = Message::factory()->for($general)->for($owner)->create(['body' => 'already fired']);

    MessageReminder::factory()->for($owner)->for($pendingMessage)->create();
    MessageReminder::factory()->for($owner)->for($firedMessage)->fired()->create();

    $this->actingAs($owner)
        ->get(route('channels.show', ['team' => $team->slug, 'channel' => $general->slug]))
        ->assertInertia(fn (Assert $page): Assert => $page
            ->has('reminders', 1)
            ->where('reminders.0.body', 'still pending')
            ->has('firedReminders', 1)
            ->where('firedReminders.0.body', 'already fired')
        );
});

test('a reminder on a since-deleted message blanks its body but keeps the link', function (): void {
    [$owner, $team, $general] = reminderListTeamWithGeneral();

    $message = Message::factory()->for($general)->for($owner)->create(['body' => 'secret plan']);
    MessageReminder::factory()->for($owner)->for($message)->create();
    $message->delete();

    $this->actingAs($owner)
        ->get(route('channels.show', ['team' => $team->slug, 'channel' => $general->slug]))
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('reminders.0.isDeleted', true)
            ->where('reminders.0.body', '')
            ->where('reminders.0.messageId', $message->id)
            ->where('reminders.0.channelSlug', 'general')
        );
});

test('reminders are scoped to the current team', function (): void {
    [$owner, $team, $general] = reminderListTeamWithGeneral();
    $message = Message::factory()->for($general)->for($owner)->create(['body' => 'in acme']);
    MessageReminder::factory()->for($owner)->for($message)->create();

    $otherTeam = app(CreateTeam::class)->handle($owner, 'Beta');
    $otherGeneral = Channel::where('team_id', $otherTeam->id)->where('slug', 'general')->firstOrFail();
    $otherMessage = Message::factory()->for($otherGeneral)->for($owner)->create(['body' => 'in beta']);
    MessageReminder::factory()->for($owner)->for($otherMessage)->create();

    $this->actingAs($owner)
        ->get(route('channels.show', ['team' => $team->slug, 'channel' => $general->slug]))
        ->assertInertia(fn (Assert $page): Assert => $page
            ->has('reminders', 1)
            ->where('reminders.0.body', 'in acme')
        );
});
