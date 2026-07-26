<?php

declare(strict_types=1);

use App\Models\Team;
use App\Models\User;
use App\Models\UserGroup;

/**
 * Layout stability on the teams pages when a validation message appears (#894).
 *
 * The tail of #883: these five pages placed `<InputError>` themselves, so their
 * fields grew by a line when the server rejected a submit while every
 * `<FormField>` consumer held still. Only a real browser can settle this — it is
 * the browser's own layout, and whether a message wraps depends on the width the
 * cell happens to have.
 *
 * @return array{owner: User, team: Team}
 */
function browserTeamForFieldErrors(): array
{
    ['owner' => $owner, 'team' => $team] = browserTeamWithChannel();

    return ['owner' => $owner, 'team' => $team];
}

/**
 * Records the top and left of each watched element, so a later assertion can
 * prove none of them moved when the message arrived.
 *
 * @param  array<string, string>  $watched  name => CSS selector
 */
function browserRecordLayout(array $watched): string
{
    $json = json_encode($watched, JSON_THROW_ON_ERROR);

    return <<<JS
    () => {
        window.__watched = {$json};

        window.__layoutBefore = Object.fromEntries(
            Object.entries(window.__watched).map(([name, selector]) => {
                const box = document.querySelector(selector).getBoundingClientRect();

                return [name, { top: box.top, left: box.left }];
            }),
        );
    }
    JS;
}

/** Whether every element recorded by `browserRecordLayout` is still where it was. */
function browserLayoutHeldStill(): string
{
    return <<<'JS'
    (() => {
        return Object.entries(window.__layoutBefore).every(([name, before]) => {
            const box = document.querySelector(window.__watched[name]).getBoundingClientRect();

            return Math.abs(box.top - before.top) < 0.5
                && Math.abs(box.left - before.left) < 0.5;
        });
    })()
    JS;
}

/**
 * The team-name row sits in a section with padding below it, so the reserved
 * gutter has somewhere to live and the message fits the 384px cell on one line.
 * `Save` sits beside the field and is top-aligned, so it never moved; what did
 * was everything the section pushes down — the members list below it, which the
 * page grew by a line under someone already scrolled to it.
 */
test('a rejected team rename leaves the sections below it exactly where they were', function (): void {
    ['owner' => $owner, 'team' => $team] = browserTeamForFieldErrors();

    $page = signInThroughBrowser($owner)
        ->navigate("/settings/teams/{$team->slug}")
        ->assertSee('Team name')
        // `login` is a reserved name (App\Rules\TeamName), so the server rejects
        // it while the browser's own `required` check passes.
        ->type('[data-test="team-name-input"]', 'login');

    $page->script(browserRecordLayout([
        'input' => '[data-test="team-name-input"]',
        'save' => '[data-test="team-save-button"]',
        'invite' => '[data-test="invite-member-button"]',
        'members' => '[data-test="member-row"]',
    ]));

    $page->click('@team-save-button')
        ->assertSee('This team name is reserved and cannot be used.');

    $page->assertScript(browserLayoutHeldStill(), true);
});

/**
 * The create row is the shape that cannot take the reservation: two narrow cells
 * at the bottom edge of a card, where a wrapped message drawn out of flow would
 * run past the card's own border. It opts out, so the card grows downward — but
 * `Create` still must not move, in either axis. Horizontally is the new risk: an
 * in-flow message is wider than its cell, and left to size the cell it would
 * shove the button sideways.
 */
test('a rejected group create keeps the Create button still and the message inside the card', function (): void {
    ['owner' => $owner, 'team' => $team] = browserTeamForFieldErrors();

    $page = signInThroughBrowser($owner)
        ->navigate("/settings/teams/{$team->slug}/groups")
        ->assertSee('User groups')
        ->type('[data-test="group-name-input"]', 'Dev Team')
        // Rejected by the handle's regex, and long enough that the message wraps
        // to several lines in the 192px cell.
        ->type('[data-test="group-slug-input"]', 'NOT VALID!!');

    $page->script(browserRecordLayout([
        'create' => '[data-test="group-create-button"]',
        'name' => '[data-test="group-name-input"]',
        'slug' => '[data-test="group-slug-input"]',
    ]));

    $page->click('@group-create-button')
        ->assertSee('The handle may only contain lowercase letters, numbers, and hyphens.');

    $page->assertScript(browserLayoutHeldStill(), true);

    // The card grew to hold the message rather than letting it escape: every
    // line of the message is drawn inside the form's own box.
    $page->assertScript(<<<'JS'
    (() => {
        const card = document.querySelector('[data-test="group-create-form"]').getBoundingClientRect();
        const message = [...document.querySelectorAll('[role="alert"]')]
            .map((node) => node.getBoundingClientRect())
            .pop();

        return message.bottom <= card.bottom + 0.5 && message.right <= card.right + 0.5;
    })()
    JS, true);
});

/**
 * The rename dialog keeps the reservation: the Members section below it gives a
 * wrapped message somewhere to spill, and the whole dialog holds still — which
 * is what stops `Save` sliding out from under the pointer.
 */
test('a rejected group rename leaves the editor dialog exactly where it was', function (): void {
    ['owner' => $owner, 'team' => $team] = browserTeamForFieldErrors();

    UserGroup::factory()->for($team)->slug('dev-team')->create();

    $page = signInThroughBrowser($owner)
        ->navigate("/settings/teams/{$team->slug}/groups")
        ->click('@group-edit-dev-team')
        ->assertPresent('[data-test="group-edit-dialog"]')
        // The dialog fades and zooms in; a baseline recorded mid-transition
        // measures the animation rather than the error line.
        ->wait(0.5)
        ->type('[data-test="group-edit-slug-input"]', 'BAD!!');

    $page->script(browserRecordLayout([
        'save' => '[data-test="group-rename-button"]',
        'members' => '[data-test="group-members-empty"]',
        'name' => '[data-test="group-edit-name-input"]',
    ]));

    $page->click('@group-rename-button')
        ->assertSee('The handle may only contain lowercase letters, numbers, and hyphens.');

    $page->assertScript(browserLayoutHeldStill(), true);
});

/**
 * The integrations dialogs are plain columns, so they take the reservation as
 * the settings modals did in #883 — the footer buttons stay put across a
 * rejected submit.
 */
test('a rejected bot create leaves the dialog footer exactly where it was', function (): void {
    ['owner' => $owner, 'team' => $team] = browserTeamForFieldErrors();

    $page = signInThroughBrowser($owner)
        ->navigate("/settings/teams/{$team->slug}/integrations")
        ->click('@new-bot-button')
        ->assertPresent('[data-test="new-bot-dialog"]')
        ->wait(0.5);

    $page->script(browserRecordLayout([
        'create' => '[data-test="bot-create-button"]',
        'input' => '[data-test="bot-name-input"]',
    ]));

    // Submitted with the name deliberately left blank.
    $page->click('@bot-create-button')
        ->assertSee('The name field is required.');

    $page->assertScript(browserLayoutHeldStill(), true);
});
