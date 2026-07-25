<?php

declare(strict_types=1);

use App\Actions\Teams\CreateTeam;
use App\Models\User;
use Database\Seeders\DemoSeeder;

/**
 * The public demo's one-click entry (#545).
 *
 * The point of the CTA is that a stranger reaches the seeded workspace without
 * ever reading the credentials, so the assertion that matters is the whole round
 * trip: land on the login screen, press one button, end up in a channel. The
 * feature suite covers the route's guard rails; this covers the click.
 */

/** Stand up the shared demo account the entry route signs visitors into. */
function seededDemoAccountForBrowserTests(): User
{
    $demo = User::factory()->create(['email' => DemoSeeder::DEMO_EMAIL]);

    app(CreateTeam::class)->handle($demo, DemoSeeder::TEAM_NAME);

    return $demo->refresh();
}

test('one click on the demo CTA lands a visitor in the seeded workspace', function (): void {
    config(['demo.mode' => true]);

    $demo = seededDemoAccountForBrowserTests();

    visit('/login')
        ->assertSee('Try the live demo')
        ->click('[data-test="demo-enter-button"]')
        ->assertUrlIs(url('/t/'.$demo->currentTeam->slug.'/c/general'))
        ->assertSee('general');
});

test('the demo entry panel has no serious accessibility violations, light or dark', function (): void {
    config(['demo.mode' => true]);

    seededDemoAccountForBrowserTests();

    $page = visit('/login')
        ->assertVisible('[data-test="demo-enter-button"]');

    // Pin the light palette before the first audit rather than trusting the
    // default: appearance is persisted in localStorage, so a page that arrives
    // already dark would audit dark twice and never exercise light at all.
    $page->script(<<<'JS'
    () => {
        localStorage.setItem('appearance', 'light');
        document.documentElement.classList.remove('dark');
        document.documentElement.style.colorScheme = 'light';
    }
    JS);

    $page->wait(0.5)
        ->assertNoAccessibilityIssues();

    // Re-audit against the dark palette. Persist 'dark' to localStorage — the
    // source of truth `useAppearance` reads — before applying the `.dark` class,
    // otherwise the appearance controller re-resolves 'system' → light and
    // reverts the toggle mid-audit. The settle lets that recompute finish.
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

test('the demo CTA is absent off the demo, so a real deployment shows no dead control', function (): void {
    config(['demo.mode' => false]);

    seededDemoAccountForBrowserTests();

    visit('/login')
        ->assertSee('Log in')
        ->assertMissing('[data-test="demo-enter-button"]');
});
