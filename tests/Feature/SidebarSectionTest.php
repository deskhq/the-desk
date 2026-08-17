<?php

declare(strict_types=1);

use Inertia\Testing\AssertableInertia as Assert;

/*
|--------------------------------------------------------------------------
| Collapsed sidebar sections (#1117)
|--------------------------------------------------------------------------
|
| `collapsedChannelSections` has no read-model of its own: `share()` ships
| `$user->collapsed_channel_sections ?? []` verbatim, so what the endpoint
| wrote is what the page carries. Each test below therefore states its claim
| against the column, and one HTTP test states the Inertia half — that the
| prop is shipped, and that a viewer who never collapsed anything gets `[]`
| rather than the column's null.
|
*/

test('a user can collapse a sidebar section', function (): void {
    ['owner' => $owner] = teamWithChannel();

    $this->actingAs($owner)
        ->patch(route('sidebar.sections.update'), ['collapsed' => ['starred']])
        ->assertRedirect();

    expect($owner->refresh()->collapsed_channel_sections)->toBe(['starred']);
});

test('an empty payload clears every collapsed section', function (): void {
    ['owner' => $owner] = teamWithChannel();
    $owner->update(['collapsed_channel_sections' => ['starred', 'channels']]);

    $this->actingAs($owner)
        ->patch(route('sidebar.sections.update'), ['collapsed' => []])
        ->assertRedirect();

    expect($owner->refresh()->collapsed_channel_sections)->toBe([]);
});

test('the workspace ships the collapsed sections, empty for a user who never collapsed one', function (): void {
    ['owner' => $owner, 'team' => $team, 'channel' => $general] = teamWithChannel();
    $show = route('channels.show', ['team' => $team->slug, 'channel' => $general->slug]);

    // The column starts null, and `share()` is what turns that into an empty
    // list the sidebar can render without a guard.
    expect($owner->collapsed_channel_sections)->toBeNull();

    $this->actingAs($owner)->get($show)
        ->assertInertia(fn (Assert $page): Assert => $page->where('collapsedChannelSections', [])->etc());

    $owner->update(['collapsed_channel_sections' => ['starred']]);

    $this->actingAs($owner)->get($show)
        ->assertInertia(fn (Assert $page): Assert => $page->where('collapsedChannelSections', ['starred'])->etc());
});

test('duplicate section keys are stored once', function (): void {
    ['owner' => $owner] = teamWithChannel();

    $this->actingAs($owner)
        ->patch(route('sidebar.sections.update'), ['collapsed' => ['channels', 'channels']])
        ->assertRedirect();

    expect($owner->refresh()->collapsed_channel_sections)->toBe(['channels']);
});

test('unknown section keys are rejected', function (): void {
    ['owner' => $owner] = teamWithChannel();

    $this->actingAs($owner)
        ->patch(route('sidebar.sections.update'), ['collapsed' => ['bogus']])
        ->assertSessionHasErrors('collapsed.0');

    expect($owner->refresh()->collapsed_channel_sections)->toBeNull();
});

test('the collapsed payload must be present', function (): void {
    ['owner' => $owner] = teamWithChannel();

    $this->actingAs($owner)
        ->patch(route('sidebar.sections.update'), [])
        ->assertSessionHasErrors('collapsed');
});

test('a guest cannot persist sidebar sections', function (): void {
    $this->patch(route('sidebar.sections.update'), ['collapsed' => ['starred']])
        ->assertRedirect(route('login'));
});
