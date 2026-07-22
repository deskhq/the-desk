<?php

declare(strict_types=1);

use App\Models\CustomEmoji;
use App\Models\Team;
use App\Models\User;

/**
 * Seed a workspace whose team owns one custom emoji created by the owner, so the
 * settings list renders a row with its `linkDestructive` "Delete" action — the
 * shared button variant whose destructive text was 3.92:1 on the dark card (#678).
 *
 * @return array{owner: User, team: Team}
 */
function browserTeamWithCustomEmoji(): array
{
    ['owner' => $alice, 'team' => $team] = browserTeamWithChannel();

    CustomEmoji::factory()->create([
        'team_id' => $team->id,
        'created_by' => $alice->id,
        'name' => 'party-parrot',
    ]);

    return ['owner' => $alice, 'team' => $team];
}

test('the custom emoji settings page passes the axe audit in either theme', function (): void {
    ['owner' => $alice, 'team' => $team] = browserTeamWithCustomEmoji();

    $page = signInThroughBrowser($alice)
        ->navigate("/settings/teams/{$team->slug}/emojis")
        ->assertSee('Custom emoji')
        // The row action is the `linkDestructive` <Button> at text-xs on `bg-card`
        // that the audit exists to guard.
        ->assertPresent('[data-test="emoji-remove-party-parrot"]')
        ->assertNoAccessibilityIssues();

    $page->script(<<<'JS'
    () => {
        localStorage.setItem('appearance', 'dark');
        document.documentElement.classList.add('dark');
        document.documentElement.style.colorScheme = 'dark';
    }
    JS);

    $page->wait(0.5)
        ->assertNoAccessibilityIssues();
});
