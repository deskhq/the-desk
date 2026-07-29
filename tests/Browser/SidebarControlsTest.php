<?php

declare(strict_types=1);

use App\Models\ChannelSection;

/**
 * A script resolving once the given selector has reached the wanted visibility —
 * `false` covering both "hidden" and "gone from the DOM", since a collapsed
 * group is kept mounted by `v-show` while a deleted section is removed outright.
 *
 * Every element assertion the browser plugin offers is a one-shot read
 * (`assertPresent` counts, `assertMissing` reads `isVisible()`), and what these
 * tests watch for lands after a server round-trip. Polling one animation frame
 * at a time settles that without pinning a sleep to whatever a loaded CI runner
 * happens to take, the same way the destinations suite polls the URL.
 */
function sidebarSelectorSettles(string $selector, bool $visible): string
{
    $wanted = $visible ? 'true' : 'false';

    return <<<JS
    (async () => {
        const settled = () =>
            (document.querySelector('{$selector}')?.checkVisibility() ?? false) === {$wanted};

        for (let frame = 0; frame < 300 && ! settled(); frame++) {
            await new Promise(requestAnimationFrame);
        }

        return settled();
    })()
    JS;
}

/** The selector for a channel's row inside one sidebar group. */
function sidebarChannelRow(string $group, string $slug): string
{
    return '[data-test="'.$group.'"] a[href$="/'.$slug.'"]';
}

test('a section can be created through the inline shadcn input', function (): void {
    ['owner' => $alice] = browserTeamWithChannel();

    // "New section" now lives in the dock header's "+ New" menu; opening the
    // inline form still focuses the shadcn <Input> (resolved by data-test, not
    // a component ref), and typing a name then pressing Enter blurs it, which
    // commits the section.
    signInThroughBrowser($alice)
        ->click('@new-menu-trigger')
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
        // over it and pressing Enter commits the rename.
        ->click("@section-menu-{$section->id}")
        ->assertPresent("@section-rename-{$section->id}")
        // Let the dropdown settle past its open/pointer-grace window, otherwise
        // the "Rename" click can be swallowed and the editor never opens.
        ->wait(0.5)
        ->click("@section-rename-{$section->id}")
        // Re-establish focus on the editor (the dropdown returns focus to its
        // trigger on close), then type over the name and press Enter to commit.
        ->click("@section-rename-input-{$section->id}")
        ->type("@section-rename-input-{$section->id}", 'New name')
        ->keys("@section-rename-input-{$section->id}", ['Enter'])
        ->assertSee('New name')
        ->assertDontSee('Old name');
});

test('deleting a section returns its channels to the default group', function (): void {
    ['owner' => $alice, 'team' => $team, 'channel' => $general] = browserTeamWithChannel();

    $section = ChannelSection::factory()->for($alice)->for($team)->create([
        'name' => 'Projects',
    ]);
    $alice->channels()->updateExistingPivot($general->id, ['section_id' => $section->id]);

    signInThroughBrowser($alice)
        ->resize(1280, 900)
        ->navigate(browserChannelUrl($team, $general))
        ->assertScript(
            sidebarSelectorSettles(sidebarChannelRow("section-content-custom-{$section->id}", 'general'), true),
            true,
        )
        ->click("@section-menu-{$section->id}")
        // Let the dropdown settle past its open/pointer-grace window, otherwise
        // the "Delete" click is swallowed and the section survives.
        ->wait(0.5)
        ->click("@section-delete-{$section->id}")
        // Deleting a section only unfiles its channels: #general falls back to
        // the default group rather than leaving the sidebar with the section.
        ->assertScript(sidebarSelectorSettles("[data-test=\"section-custom-{$section->id}\"]", false), true)
        ->assertScript(sidebarSelectorSettles(sidebarChannelRow('section-content-channels', 'general'), true), true);
});

test('a collapsed built-in section and a collapsed custom section both survive a reload', function (): void {
    ['owner' => $alice, 'team' => $team, 'channel' => $general] = browserTeamWithChannel();

    $section = ChannelSection::factory()->for($alice)->for($team)->create([
        'name' => 'Projects',
    ]);

    $page = signInThroughBrowser($alice)
        ->resize(1280, 900)
        ->navigate(browserChannelUrl($team, $general))
        ->assertScript(sidebarSelectorSettles('[data-test="section-content-channels"]', true), true)
        // The two mechanisms are separate: the built-in set lives on the user,
        // the custom flag on the section itself.
        ->click('@section-toggle-channels')
        ->click("@section-toggle-custom-{$section->id}")
        ->assertScript(sidebarSelectorSettles('[data-test="section-content-channels"]', false), true)
        ->assertScript(sidebarSelectorSettles("[data-test=\"section-content-custom-{$section->id}\"]", false), true);

    // Both toggles are optimistic, so a reload proves nothing until their
    // PATCHes have landed. Poll for that rather than sleeping through it.
    retry(30, function () use ($alice, $section): void {
        expect($alice->refresh()->collapsed_channel_sections)->toContain('channels');
        expect($section->refresh()->collapsed)->toBeTrue();
    }, 100);

    $page->navigate(browserChannelUrl($team, $general))
        ->assertScript(sidebarSelectorSettles('[data-test="section-toggle-channels"]', true), true)
        ->assertScript(sidebarSelectorSettles('[data-test="section-content-channels"]', false), true)
        ->assertScript(sidebarSelectorSettles("[data-test=\"section-content-custom-{$section->id}\"]", false), true);
});

test('a channel is filed under a section from its kebab menu', function (): void {
    ['owner' => $alice, 'team' => $team, 'channel' => $general] = browserTeamWithChannel();

    $section = ChannelSection::factory()->for($alice)->for($team)->create([
        'name' => 'Projects',
    ]);

    signInThroughBrowser($alice)
        ->resize(1280, 900)
        ->navigate(browserChannelUrl($team, $general))
        ->assertScript(sidebarSelectorSettles(sidebarChannelRow('section-content-channels', 'general'), true), true)
        // The row's kebab only carries "Move to" once a section exists to move into.
        ->click('@channel-menu-general')
        ->wait(0.5)
        ->click("@move-to-{$section->id}")
        ->assertScript(
            sidebarSelectorSettles(sidebarChannelRow("section-content-custom-{$section->id}", 'general'), true),
            true,
        )
        ->assertScript(sidebarSelectorSettles(sidebarChannelRow('section-content-channels', 'general'), false), true);
});
