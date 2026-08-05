<?php

use App\Enums\AppLocale;
use App\Enums\PostRegistrationPrompt;
use App\Models\User;
use App\Support\Branding\BrandingAssets;
use Illuminate\Support\Facades\Date;

/*
|--------------------------------------------------------------------------
| The once'd shell props (#1251)
|--------------------------------------------------------------------------
|
| Roughly twenty shared props are instance constants and session facts that
| cannot change between two clicks. They are declared in `shareOnce()` and
| excluded from the second visit onwards, so this file's subject is the
| difference between a first load and a second click — which is an HTTP fact,
| and only reachable through a real Inertia visit (the exclusion happens in
| the response pipeline, so a direct call to `share()` would not exercise it).
|
| `revisit()` in `tests/Helpers.php` is what makes the second click: it reads
| the once keys off the previous response and declares them back, exactly as
| the client does.
|
*/

/**
 * Every prop this issue moves out of the ordinary navigation.
 *
 * @var list<string>
 */
const ONCE_PROPS = [
    // Instance config, behind one fingerprint.
    'name',
    'branding',
    'reverb',
    'webPush',
    'registrationEnabled',
    'emailVerificationEnabled',
    'demoMode',
    'sso',
    'attachments',
    'gifPickerEnabled',
    'pollsEnabled',
    'integrationsEnabled',
    'channelRestoreWindowDays',
    'presence',
    // TTL'd.
    'demoResetsAt',
    'update',
    // Session-, locale- or version-keyed.
    'locale',
    'translations',
    'currentDevice',
    'slashCommands',
    'sidebarPositions',
    'invitableRoles',
    'postRegistrationPrompt',
];

test('a first load carries every once prop', function (): void {
    ['owner' => $viewer, 'team' => $team] = teamWithChannel();

    $page = visitWorkspaceAs($viewer, $team)->assertOk()->viewData('page');

    expect(array_keys($page['props']))->toContain(...ONCE_PROPS);
});

test('a second visit carries none of them', function (): void {
    ['owner' => $viewer, 'team' => $team] = teamWithChannel();

    $first = visitWorkspaceAs($viewer, $team)->assertOk();

    $second = revisit($first, workspaceUrl($team))->assertOk();

    expect(array_intersect(ONCE_PROPS, array_keys((array) $second->json('props'))))->toBe([]);
});

test('a second visit still carries what an ordinary navigation owes', function (): void {
    ['owner' => $viewer, 'team' => $team] = teamWithChannel();

    $first = visitWorkspaceAs($viewer, $team)->assertOk();

    $props = (array) revisit($first, workspaceUrl($team))->assertOk()->json('props');

    expect(array_keys($props))->toContain('errors', 'unread', 'auth', 'sidebarOpen')
        ->and($props['sidebarOpen'])->toBeTrue();
});

test('a partial reload naming one of them still receives it', function (): void {
    ['owner' => $viewer, 'team' => $team] = teamWithChannel();

    $first = visitWorkspaceAs($viewer, $team)->assertOk();

    // The sixteen `only: [...]` call sites already in the app keep working for
    // exactly this reason: the once exclusion is gated on the request not being
    // a partial one, so naming a prop is enough to have it resolved again.
    $reloaded = revisit($first, workspaceUrl($team), [
        'X-Inertia-Partial-Component' => (string) $first->viewData('page')['component'],
        'X-Inertia-Partial-Data' => 'branding,slashCommands',
    ])->assertOk();

    expect(array_keys((array) $reloaded->json('props')))
        ->toContain('branding', 'slashCommands')
        ->not->toContain('presence');
});

test('changing a config value reships the whole instance group on the next visit', function (): void {
    ['owner' => $viewer, 'team' => $team] = teamWithChannel();

    $first = visitWorkspaceAs($viewer, $team)->assertOk();

    // Whichever value moves, the fingerprint moves with it, so the group comes
    // back together rather than one prop at a time. `slashCommands` rides along
    // for a reason of its own: `/poll` is registered only where polls are on, so
    // switching them off has to take the command out of autocomplete with it.
    config(['polls.enabled' => ! config('polls.enabled')]);

    expect(array_keys((array) revisit($first, workspaceUrl($team))->assertOk()->json('props')))
        ->toContain('pollsEnabled', 'name', 'branding', 'presence', 'slashCommands', 'update');
});

test('leaving the config alone keeps the instance group off the next visit', function (): void {
    ['owner' => $viewer, 'team' => $team] = teamWithChannel();

    $first = visitWorkspaceAs($viewer, $team)->assertOk();

    expect(array_keys((array) revisit($first, workspaceUrl($team))->assertOk()->json('props')))
        ->not->toContain('pollsEnabled', 'name', 'branding', 'presence', 'slashCommands', 'update');
});

test('a second visit no longer stats the branding directory', function (): void {
    ['owner' => $viewer, 'team' => $team] = teamWithChannel();

    $branding = Mockery::spy(BrandingAssets::class);
    $this->instance(BrandingAssets::class, $branding);

    $first = visitWorkspaceAs($viewer, $team)->assertOk();
    revisit($first, workspaceUrl($team))->assertOk();

    // Stands in for the whole group: a once prop is excluded *before* it is
    // resolved, so the work behind it — this stat, and the user-agent regexes
    // behind `currentDevice` — happens on the first load and never again.
    $branding->shouldHaveReceived('logoPath')->once();
});

test('the demo reset instant holds until the top of the next hour', function (): void {
    config(['demo.mode' => true]);
    $this->travelTo(Date::parse('2026-08-04 14:20:00'));

    ['owner' => $viewer, 'team' => $team] = teamWithChannel();

    $visit = visitWorkspaceAs($viewer, $team)->assertOk();

    expect($visit->viewData('page')['props']['demoResetsAt'])
        ->toBe(Date::parse('2026-08-04 15:00:00')->toIso8601String())
        ->and(onceExpiry($visit, 'demoResetsAt'))
        ->toBe(Date::parse('2026-08-04 15:00:00')->getTimestamp() * 1000);
});

test('off the demo there is no reset instant and nothing to expire', function (): void {
    ['owner' => $viewer, 'team' => $team] = teamWithChannel();

    $visit = visitWorkspaceAs($viewer, $team)->assertOk();

    expect($visit->viewData('page')['props']['demoResetsAt'])->toBeNull()
        ->and(onceExpiry($visit, 'demoResetsAt'))->toBeNull();
});

test('the version standing holds for as long as the check that feeds it', function (): void {
    config(['updates.cache_ttl_hours' => 6]);
    $this->travelTo(Date::parse('2026-08-04 14:20:00'));

    ['owner' => $viewer, 'team' => $team] = teamWithChannel();

    expect(onceExpiry(visitWorkspaceAs($viewer, $team)->assertOk(), 'update'))
        ->toBe(Date::parse('2026-08-04 20:20:00')->getTimestamp() * 1000);
});

test('a nonsensical check interval still leaves the version standing an interval', function (): void {
    config(['updates.cache_ttl_hours' => 0]);
    $this->travelTo(Date::parse('2026-08-04 14:20:00'));

    ['owner' => $viewer, 'team' => $team] = teamWithChannel();

    // An expiry in the present is one the client treats as already past, which
    // would put the prop back on every navigation — the one thing this file is
    // about. The floor is the same one `presence.away_after_minutes` carries.
    expect(onceExpiry(visitWorkspaceAs($viewer, $team)->assertOk(), 'update'))
        ->toBe(Date::parse('2026-08-04 15:20:00')->getTimestamp() * 1000);
});

test('signing in without a document load still ships the version standing', function (): void {
    ['owner' => $viewer, 'team' => $team] = teamWithChannel();

    // A guest is handed null, and it is that null the client caches. Were the two
    // answers shared one key, the sign-in that follows in the same tab would be
    // told it already holds the prop and the indicator would never appear.
    $guest = $this->get(route('login'))->assertOk();

    expect($guest->viewData('page')['props']['update'])->toBeNull();

    $this->actingAs($viewer);

    expect((array) revisit($guest, workspaceUrl($team))->assertOk()->json('props.update'))
        ->toHaveKey('current');
});

test('a queued prompt is forced through even into a tab that has cached the empty answer', function (): void {
    config(['fortify.passkeys_enabled' => true]);

    ['owner' => $viewer, 'team' => $team] = teamWithChannel();

    // Nothing queued: the answer is null, and the client caches it.
    $first = visitWorkspaceAs($viewer, $team)->assertOk();

    expect(revisit($first, workspaceUrl($team))->assertOk()->json('props'))
        ->not->toHaveKey('postRegistrationPrompt');

    $this->withSession([PostRegistrationPrompt::SESSION_KEY => PostRegistrationPrompt::Passkey->value]);

    expect(revisit($first, workspaceUrl($team))->assertOk()->json('props.postRegistrationPrompt'))
        ->toBe(PostRegistrationPrompt::Passkey->value);
});

test('reading in a new language ships the props whose copy is translated', function (): void {
    ['owner' => $viewer, 'team' => $team] = teamWithChannel();

    $first = visitWorkspaceAs($viewer, $team)->assertOk();

    // The same failure `translations` guards against (#764), for the props that
    // carry server-translated copy: a locale that changes without a document
    // load must not leave the client rendering the previous language's labels.
    $viewer->update(['locale' => AppLocale::French->value]);

    expect(array_keys((array) revisit($first, workspaceUrl($team))->assertOk()->json('props')))
        ->toContain('locale', 'translations', 'sidebarPositions', 'invitableRoles', 'slashCommands')
        ->not->toContain('name', 'branding');
});

test('arriving on another device ships that device its own name', function (): void {
    ['owner' => $viewer, 'team' => $team] = teamWithChannel();

    $first = $this->actingAs($viewer)
        ->withHeader('User-Agent', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) Chrome/140.0.0.0')
        ->get(workspaceUrl($team))
        ->assertOk();

    expect($first->viewData('page')['props']['currentDevice'])
        ->toBe(['browser' => 'Chrome', 'platform' => 'macOS']);

    $onPhone = revisit($first, workspaceUrl($team), [
        'User-Agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) FxiOS/120.0',
    ])->assertOk();

    expect($onPhone->json('props.currentDevice'))
        ->toBe(['browser' => 'Firefox', 'platform' => 'iOS']);
});

test('off a workspace route the same props are carried once and then not at all', function (): void {
    $viewer = User::factory()->create();

    $first = $this->actingAs($viewer)->get(route('profile.edit'))->assertOk();

    expect(array_keys($first->viewData('page')['props']))->toContain(...ONCE_PROPS)
        ->and(array_intersect(ONCE_PROPS, array_keys((array) revisit($first, route('profile.edit'))->assertOk()->json('props'))))
        ->toBe([]);
});
