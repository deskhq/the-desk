<?php

declare(strict_types=1);

use App\Models\Message;
use App\Models\MessageReminder;
use Inertia\Testing\AssertableInertia as Assert;

/*
|--------------------------------------------------------------------------
| The reminder props, as the page ships them (#1117)
|--------------------------------------------------------------------------
|
| What is left here is the half only HTTP can show: that a workspace render
| carries the viewer's reminders at all, and that pending and fired ones arrive
| under two separate props — which is what lets the list and the nudges be
| driven independently on the client.
|
| Which reminders those are, in what order, and how much of each one a viewer
| who lost access still sees is proven against the read-model in
| `tests/Integration/Support/SidebarRemindersTest.php`.
|
*/

test('a workspace render ships pending and fired reminders under separate props', function (): void {
    ['owner' => $owner, 'team' => $team, 'channel' => $general] = teamWithChannel();

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
