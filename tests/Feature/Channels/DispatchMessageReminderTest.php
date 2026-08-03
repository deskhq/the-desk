<?php

use App\Actions\Channels\DispatchDueMessageReminders;
use App\Enums\MessageReminderStatus;
use App\Events\MessageReminderDue;
use App\Models\Message;
use App\Models\MessageReminder;
use Illuminate\Support\Facades\Event;

/** Run the per-minute reminder scan. */
function fireDueReminders(): void
{
    app(DispatchDueMessageReminders::class)->handle();
}

test('a due reminder fires and signals its owner', function (): void {
    Event::fake([MessageReminderDue::class]);

    ['owner' => $owner, 'channel' => $general] = teamWithChannel();
    $message = Message::factory()->for($general)->for($owner)->create();
    $reminder = MessageReminder::factory()->for($owner)->for($message)->due()->create();

    fireDueReminders();

    $reminder->refresh();

    expect($reminder->status)->toBe(MessageReminderStatus::Fired)
        ->and($reminder->fired_at)->not->toBeNull();

    Event::assertDispatched(MessageReminderDue::class, fn (MessageReminderDue $event): bool => $event->userId === $owner->id
        && $event->broadcastOn()[0]->name === 'private-user.'.$owner->id);
});

test('a reminder whose time has not arrived is left pending', function (): void {
    Event::fake([MessageReminderDue::class]);

    ['owner' => $owner, 'channel' => $general] = teamWithChannel();
    $message = Message::factory()->for($general)->for($owner)->create();
    $reminder = MessageReminder::factory()->for($owner)->for($message)->create([
        'remind_at' => now()->addHour(),
    ]);

    fireDueReminders();

    expect($reminder->refresh()->status)->toBe(MessageReminderStatus::Pending);
    Event::assertNotDispatched(MessageReminderDue::class);
});

test('an already-fired reminder never fires twice', function (): void {
    Event::fake([MessageReminderDue::class]);

    ['owner' => $owner, 'channel' => $general] = teamWithChannel();
    $message = Message::factory()->for($general)->for($owner)->create();
    MessageReminder::factory()->for($owner)->for($message)->fired()->create([
        'remind_at' => now()->subHour(),
    ]);

    fireDueReminders();

    Event::assertNotDispatched(MessageReminderDue::class);
});
