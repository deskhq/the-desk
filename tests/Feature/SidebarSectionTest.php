<?php

use App\Models\Channel;
use App\Models\Team;
use App\Models\User;

/**
 * Read the shared `collapsedChannelSections` prop for the acting user off a
 * channel page (where the workspace sidebar is rendered).
 *
 * @return array<int, string>
 */
function sidebarCollapsedProp(User $user, Team $team, Channel $channel): array
{
    $response = test()->actingAs($user)->get(route('channels.show', [
        'team' => $team->slug,
        'channel' => $channel->slug,
    ]))->assertOk();

    return $response->viewData('page')['props']['collapsedChannelSections'];
}

test('a user can collapse a sidebar section', function (): void {
    ['owner' => $owner, 'team' => $team, 'channel' => $general] = teamWithChannel();

    $this->actingAs($owner)
        ->patch(route('sidebar.sections.update'), ['collapsed' => ['starred']])
        ->assertRedirect();

    expect($owner->refresh()->collapsed_channel_sections)->toBe(['starred']);
    expect(sidebarCollapsedProp($owner, $team, $general))->toBe(['starred']);
});

test('an empty payload clears every collapsed section', function (): void {
    ['owner' => $owner, 'team' => $team, 'channel' => $general] = teamWithChannel();
    $owner->update(['collapsed_channel_sections' => ['starred', 'channels']]);

    $this->actingAs($owner)
        ->patch(route('sidebar.sections.update'), ['collapsed' => []])
        ->assertRedirect();

    expect($owner->refresh()->collapsed_channel_sections)->toBe([]);
    expect(sidebarCollapsedProp($owner, $team, $general))->toBe([]);
});

test('collapsed sections default to empty for a fresh user', function (): void {
    ['owner' => $owner, 'team' => $team, 'channel' => $general] = teamWithChannel();

    expect($owner->collapsed_channel_sections)->toBeNull();
    expect(sidebarCollapsedProp($owner, $team, $general))->toBe([]);
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
