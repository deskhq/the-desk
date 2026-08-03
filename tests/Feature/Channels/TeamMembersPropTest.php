<?php

use App\Support\WorkspaceShell;
use Inertia\Testing\AssertableInertia as Assert;

test('the workspace ships the team members for the DM entry points', function (): void {
    ['owner' => $owner, 'team' => $team, 'channel' => $general] = teamWithChannel();
    teamMemberInChannel($general, ['name' => 'Amy Member']);

    $this->actingAs($owner)
        ->get(route('channels.show', ['team' => $team->slug, 'channel' => $general->slug]))
        ->assertInertia(fn (Assert $page): Assert => $page
            // Who the roster holds and in what order is the shell's claim
            // (tests/Integration/Support/WorkspaceShellTest.php). What the render
            // owes is shipping all of them, so the count is taken off the shell
            // rather than re-derived here.
            ->has('teamMembers', count(new WorkspaceShell($owner, $team)->teamMembers()))
        );
});

test('the team members prop is absent off the channel workspace', function (): void {
    ['owner' => $owner] = teamWithChannel();

    $this->actingAs($owner)
        ->get(route('profile.edit'))
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('teamMembers', [])
        );
});
