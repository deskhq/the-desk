<?php

declare(strict_types=1);

use App\Actions\Teams\CreateTeam;
use App\Models\ChannelMember;
use App\Models\ChannelSection;
use App\Models\Team;
use App\Models\User;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

/*
|--------------------------------------------------------------------------
| Sidebar section endpoints (#1117)
|--------------------------------------------------------------------------
|
| The endpoints that create, rename, collapse, reorder and delete a section,
| and who may reach them. What the sidebar then *lists* — whose sections, in
| what order, carrying what state — is stated against `WorkspaceShell::
| channelSections()` in `tests/Integration/Support/WorkspaceShellTest.php`;
| one render below keeps the Inertia half, that `share()` ships the prop.
|
*/

/**
 * Create a section for the user via the endpoint.
 */
function createSection(User $user, Team $team, string $name): TestResponse
{
    return test()->actingAs($user)->post(route('channels.sections.store', ['team' => $team->slug]), [
        'name' => $name,
    ]);
}

test('a member can create a custom section and the sidebar is sent it', function (): void {
    ['owner' => $owner, 'team' => $team, 'channel' => $general] = teamWithChannel();

    createSection($owner, $team, 'My Projects')->assertRedirect();

    $this->assertDatabaseHas('channel_sections', [
        'user_id' => $owner->id,
        'team_id' => $team->id,
        'name' => 'My Projects',
        'collapsed' => false,
    ]);

    $this->actingAs($owner)
        ->get(route('channels.show', ['team' => $team->slug, 'channel' => $general->slug]))
        ->assertInertia(fn (Assert $page): Assert => $page
            ->has('channelSections', 1)
            ->where('channelSections.0.name', 'My Projects')
            ->etc());
});

test('new sections are appended after existing ones', function (): void {
    ['owner' => $owner, 'team' => $team] = teamWithChannel();

    createSection($owner, $team, 'First')->assertRedirect();
    createSection($owner, $team, 'Second')->assertRedirect();

    $positions = $owner->channelSections()->where('team_id', $team->id)->orderBy('position')->pluck('name', 'position');

    expect($positions[1] ?? null)->toBe('First')
        ->and($positions[2] ?? null)->toBe('Second');
});

test('the section name is trimmed and required', function (): void {
    ['owner' => $owner, 'team' => $team] = teamWithChannel();

    createSection($owner, $team, '   Trimmed   ')->assertRedirect();
    $this->assertDatabaseHas('channel_sections', ['name' => 'Trimmed']);

    createSection($owner, $team, '   ')->assertSessionHasErrors('name');
});

test('a section name is capped at 50 characters', function (): void {
    ['owner' => $owner, 'team' => $team] = teamWithChannel();

    createSection($owner, $team, str_repeat('a', 51))->assertSessionHasErrors('name');
});

test('a non-member cannot create a section in the team', function (): void {
    ['team' => $team] = teamWithChannel();
    $stranger = User::factory()->create();

    createSection($stranger, $team, 'Nope')->assertForbidden();

    $this->assertDatabaseMissing('channel_sections', ['user_id' => $stranger->id]);
});

test('a member can rename their section', function (): void {
    ['owner' => $owner, 'team' => $team] = teamWithChannel();
    $section = ChannelSection::factory()->for($owner)->for($team)->create(['name' => 'Old']);

    $this->actingAs($owner)
        ->patch(route('channels.sections.update', ['team' => $team->slug, 'section' => $section->id]), ['name' => 'New'])
        ->assertRedirect();

    expect($section->refresh()->name)->toBe('New');
});

test('a member can collapse their section', function (): void {
    ['owner' => $owner, 'team' => $team] = teamWithChannel();
    $section = ChannelSection::factory()->for($owner)->for($team)->create();

    $this->actingAs($owner)
        ->patch(route('channels.sections.update', ['team' => $team->slug, 'section' => $section->id]), ['collapsed' => true])
        ->assertRedirect();

    expect($section->refresh()->collapsed)->toBeTrue();
});

test('an empty section update is rejected', function (): void {
    ['owner' => $owner, 'team' => $team] = teamWithChannel();
    $section = ChannelSection::factory()->for($owner)->for($team)->create();

    $this->actingAs($owner)
        ->patch(route('channels.sections.update', ['team' => $team->slug, 'section' => $section->id]), [])
        ->assertSessionHasErrors(['name', 'collapsed']);
});

test('a member cannot update another user section', function (): void {
    ['owner' => $owner, 'team' => $team, 'channel' => $general] = teamWithChannel();
    $other = teamMemberInChannel($general);
    $section = ChannelSection::factory()->for($owner)->for($team)->create();

    $this->actingAs($other)
        ->patch(route('channels.sections.update', ['team' => $team->slug, 'section' => $section->id]), ['name' => 'Hijacked'])
        ->assertForbidden();

    expect($section->refresh()->name)->not->toBe('Hijacked');
});

test('a section from another team cannot be updated through this team', function (): void {
    ['owner' => $owner, 'team' => $team] = teamWithChannel();
    $otherTeam = app(CreateTeam::class)->handle($owner, 'Other');
    $section = ChannelSection::factory()->for($owner)->for($otherTeam)->create();

    $this->actingAs($owner)
        ->patch(route('channels.sections.update', ['team' => $team->slug, 'section' => $section->id]), ['name' => 'X'])
        ->assertForbidden();
});

test('a member can delete their section and its channels fall back to the default group', function (): void {
    ['owner' => $owner, 'team' => $team, 'channel' => $general] = teamWithChannel();
    $section = ChannelSection::factory()->for($owner)->for($team)->create();
    $owner->channels()->updateExistingPivot($general->id, ['section_id' => $section->id]);

    $this->actingAs($owner)
        ->delete(route('channels.sections.destroy', ['team' => $team->slug, 'section' => $section->id]))
        ->assertRedirect();

    $this->assertDatabaseMissing('channel_sections', ['id' => $section->id]);
    $this->assertDatabaseHas('channel_members', [
        'channel_id' => $general->id,
        'user_id' => $owner->id,
        'section_id' => null,
    ]);
});

test('a member cannot delete another user section', function (): void {
    ['owner' => $owner, 'team' => $team, 'channel' => $general] = teamWithChannel();
    $other = teamMemberInChannel($general);
    $section = ChannelSection::factory()->for($owner)->for($team)->create();

    $this->actingAs($other)
        ->delete(route('channels.sections.destroy', ['team' => $team->slug, 'section' => $section->id]))
        ->assertForbidden();

    $this->assertDatabaseHas('channel_sections', ['id' => $section->id]);
});

test('a member can reorder their sections', function (): void {
    ['owner' => $owner, 'team' => $team] = teamWithChannel();
    $a = ChannelSection::factory()->for($owner)->for($team)->position(0)->create(['name' => 'A']);
    $b = ChannelSection::factory()->for($owner)->for($team)->position(1)->create(['name' => 'B']);
    $c = ChannelSection::factory()->for($owner)->for($team)->position(2)->create(['name' => 'C']);

    $this->actingAs($owner)
        ->patch(route('channels.sections.reorder', ['team' => $team->slug]), [
            'sections' => [$c->id, $a->id, $b->id],
        ])
        ->assertRedirect();

    expect($c->refresh()->position)->toBe(0)
        ->and($a->refresh()->position)->toBe(1)
        ->and($b->refresh()->position)->toBe(2);
});

test('reordering rejects a section the user does not own', function (): void {
    ['owner' => $owner, 'team' => $team] = teamWithChannel();
    $mine = ChannelSection::factory()->for($owner)->for($team)->create();
    $other = User::factory()->create();
    $theirs = ChannelSection::factory()->for($other)->for($team)->create();

    $this->actingAs($owner)
        ->patch(route('channels.sections.reorder', ['team' => $team->slug]), [
            'sections' => [$mine->id, $theirs->id],
        ])
        ->assertSessionHasErrors('sections.1');
});

test('the sections payload must be present to reorder', function (): void {
    ['owner' => $owner, 'team' => $team] = teamWithChannel();

    $this->actingAs($owner)
        ->patch(route('channels.sections.reorder', ['team' => $team->slug]), [])
        ->assertSessionHasErrors('sections');
});

test('a section and its channel memberships relate both ways', function (): void {
    ['owner' => $owner, 'team' => $team, 'channel' => $general] = teamWithChannel();
    $section = ChannelSection::factory()->for($owner)->for($team)->create();
    $owner->channels()->updateExistingPivot($general->id, ['section_id' => $section->id]);

    $membership = ChannelMember::where('channel_id', $general->id)
        ->where('user_id', $owner->id)
        ->firstOrFail();

    expect($membership->section->is($section))->toBeTrue()
        ->and($section->channelMembers->pluck('id')->all())->toContain($membership->id);
});

test('a guest cannot manage sections', function (): void {
    ['team' => $team] = teamWithChannel();

    $this->post(route('channels.sections.store', ['team' => $team->slug]), ['name' => 'X'])
        ->assertRedirect(route('login'));
});
