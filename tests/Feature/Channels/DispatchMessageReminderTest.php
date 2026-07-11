<?php

use App\Actions\Channels\DispatchDueMessageReminders;
use App\Actions\Teams\CreateTeam;
use App\Enums\MessageReminderStatus;
use App\Events\MessageReminderDue;
use App\Models\Channel;
use App\Models\Message;
use App\Models\MessageReminder;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Event;

/**
 * Create a team with its owner already a member of #general.
 *
 * @return array{0: User, 1: Team, 2: Channel}
 */
function reminderDispatchTeamWithGeneral(): array
{
    $owner = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'Acme');
    $general = Channel::where('team_id', $team->id)->where('slug', 'general')->firstOrFail();

    return [$owner, $team, $general];
}

/** Run the per-minute reminder scan. */
function fireDueReminders(): void
{
    app(DispatchDueMessageReminders::class)->handle();
}

test('a due reminder fires and signals its owner', function () {
    Event::fake([MessageReminderDue::class]);

    [$owner, $team, $general] = reminderDispatchTeamWithGeneral();
    $message = Message::factory()->for($general)->for($owner)->create();
    $reminder = MessageReminder::factory()->for($owner)->for($message)->due()->create();

    fireDueReminders();

    $reminder->refresh();

    expect($reminder->status)->toBe(MessageReminderStatus::Fired)
        ->and($reminder->fired_at)->not->toBeNull();

    Event::assertDispatched(MessageReminderDue::class, function (MessageReminderDue $event) use ($owner) {
        return $event->userId === $owner->id
            && $event->broadcastOn()[0]->name === 'private-user.'.$owner->id;
    });
});

test('a reminder whose time has not arrived is left pending', function () {
    Event::fake([MessageReminderDue::class]);

    [$owner, $team, $general] = reminderDispatchTeamWithGeneral();
    $message = Message::factory()->for($general)->for($owner)->create();
    $reminder = MessageReminder::factory()->for($owner)->for($message)->create([
        'remind_at' => now()->addHour(),
    ]);

    fireDueReminders();

    expect($reminder->refresh()->status)->toBe(MessageReminderStatus::Pending);
    Event::assertNotDispatched(MessageReminderDue::class);
});

test('an already-fired reminder never fires twice', function () {
    Event::fake([MessageReminderDue::class]);

    [$owner, $team, $general] = reminderDispatchTeamWithGeneral();
    $message = Message::factory()->for($general)->for($owner)->create();
    MessageReminder::factory()->for($owner)->for($message)->fired()->create([
        'remind_at' => now()->subHour(),
    ]);

    fireDueReminders();

    Event::assertNotDispatched(MessageReminderDue::class);
});
