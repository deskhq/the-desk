<?php

declare(strict_types=1);

use App\Models\ChannelSection;

test('a section can be created through the inline shadcn input', function (): void {
    ['owner' => $alice] = browserTeamWithChannel();

    // Opening the inline form focuses the shadcn <Input> (resolved by data-test,
    // not a component ref); typing a name and pressing Enter blurs it, which
    // commits the section.
    signInThroughBrowser($alice)
        ->click('@create-section-trigger')
        ->type('@create-section-input', 'Projects')
        ->keys('@create-section-input', ['Enter'])
        ->assertSee('Projects');
});

test('a section can be renamed through the inline shadcn input', function (): void {
    ['owner' => $alice, 'team' => $team] = browserTeamWithChannel();

    $section = ChannelSection::factory()->for($alice)->for($team)->create([
        'name' => 'Old name',
    ]);

    signInThroughBrowser($alice)
        ->assertSee('Old name')
        // Rename from the section's kebab menu opens the inline editor; typing
        // over it and blurring (clicking away) commits the rename.
        ->click("@section-menu-{$section->id}")
        ->click("@section-rename-{$section->id}")
        // Re-establish focus on the editor (the dropdown returns focus to its
        // trigger on close), then type over the name and press Enter to commit.
        ->click("@section-rename-input-{$section->id}")
        ->type("@section-rename-input-{$section->id}", 'New name')
        ->keys("@section-rename-input-{$section->id}", ['Enter'])
        ->assertSee('New name')
        ->assertDontSee('Old name');
});
