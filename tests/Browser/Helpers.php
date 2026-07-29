<?php

declare(strict_types=1);

use App\Actions\Channels\JoinChannel;
use App\Actions\Teams\CreateTeam;
use App\Enums\TeamRole;
use App\Models\Channel;
use App\Models\Team;
use App\Models\User;
use Illuminate\Contracts\Http\Kernel as HttpKernelContract;
use Illuminate\Foundation\Http\Kernel;
use Pest\Browser\Api\AwaitableWebpage;
use Tests\Browser\Support\ForgetGuardsPerRequest;
use Tests\Browser\Support\PersistentArraySession;

/**
 * Point broadcasting at a real Reverb server for the duration of a browser test.
 *
 * The browser plugin serves the app in-process (see
 * Pest\Browser\Drivers\LaravelHttpServer), so this runtime config override
 * reaches every request the browser makes. phpunit.xml forces
 * BROADCAST_CONNECTION=null for the headless suites; here we flip it to `reverb`
 * so `ShouldBroadcast` events actually reach the second client over WebSockets.
 *
 * The browser-facing (`public_*`) host/port/scheme are derived from the same
 * server-facing REVERB_* connection the PHP process publishes to. Browser and
 * server are co-located (same Sail container locally, same runner in CI), so a
 * single host reaches Reverb from both — `reverb:8080` under Sail, `127.0.0.1:8080`
 * in CI — with no separate public endpoint to configure.
 */
function useReverbForBrowserTests(): void
{
    $options = config('broadcasting.connections.reverb.options');

    config([
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.public_host' => $options['host'] ?? '127.0.0.1',
        'broadcasting.connections.reverb.public_port' => $options['port'] ?? 8080,
        'broadcasting.connections.reverb.public_scheme' => $options['scheme'] ?? 'http',
    ]);

    // The app boots with BROADCAST_CONNECTION=null (phpunit.xml), so channels.php
    // registered its authorizations on the null broadcaster. Re-require it now that
    // `reverb` is the default connection, so every private/presence subscription's
    // authorization callback attaches to the broadcaster the browser actually uses —
    // otherwise `/broadcasting/auth` denies every channel before any callback runs.
    require base_path('routes/channels.php');

    // Force each request to re-resolve auth from its own session, so two browser
    // contexts can act as two users despite the shared long-lived test server.
    // Registered as global middleware (deduped) on the *singleton* kernel the
    // in-process server resolves (via the contract), so it runs ahead of the
    // session guard's first resolution on every request.
    $kernel = app(HttpKernelContract::class);

    if ($kernel instanceof Kernel && ! $kernel->hasMiddleware(ForgetGuardsPerRequest::class)) {
        $kernel->prependMiddleware(ForgetGuardsPerRequest::class);
    }

    // TestCase rebuilds the application (and the `array` driver's in-memory session
    // store) before each test, but the browser keeps firing asynchronous requests
    // that can land after the rebuild and load an empty session — bouncing the page
    // to /login mid-test. Pin one handler that survives the refresh so a signed-in
    // browser stays signed in for the life of its test.
    PersistentArraySession::attach();
}

/**
 * A team, two members, and the team's #general channel that both belong to.
 *
 * Both users are real channel members so each is authorized to subscribe to the
 * channel's realtime broadcasts (see routes/channels.php). #general is the
 * channel both land on straight after login, so tests never need a full-page
 * `navigate()` (which drops the browser session under the in-process test
 * server) to reach a shared room.
 *
 * @return array{owner: User, member: User, team: Team, channel: Channel}
 */
function browserTeamWithChannel(): array
{
    $owner = User::factory()->create(['name' => 'Alice Owner']);
    $member = User::factory()->create(['name' => 'Bob Member']);

    $team = app(CreateTeam::class)->handle($owner, 'Acme');
    $team->memberships()->create(['user_id' => $member->id, 'role' => TeamRole::Member]);

    $channel = Channel::where('team_id', $team->id)
        ->where('slug', Channel::GENERAL_SLUG)
        ->firstOrFail();

    // The creator auto-joins #general; the second member must be joined explicitly
    // so they are authorized to subscribe to its realtime channel.
    app(JoinChannel::class)->handle($channel, $member);

    // Every factory user gets their own personal team as their current team, so
    // point the second member at the shared team — otherwise they land on their
    // personal #general after login instead of the room the owner is in.
    $member->update(['current_team_id' => $team->id]);

    return ['owner' => $owner, 'member' => $member, 'team' => $team, 'channel' => $channel];
}

/**
 * The in-app URL for a channel's message timeline.
 */
function browserChannelUrl(Team $team, Channel $channel): string
{
    return "/t/{$team->slug}/c/{$channel->slug}";
}

/**
 * Press and hold a message row: pointer down, wait out the 500ms hold, lift.
 *
 * Synthesised as touch on purpose — the gesture is the touch stand-in for
 * hover. The hold happens between two script calls so the browser's own clock
 * drives the composable's timer.
 */
function longPressMessage(AwaitableWebpage $page, string $messageId): AwaitableWebpage
{
    $press = fn (string $type): string => <<<JS
    (() => {
        const row = document.getElementById('message-{$messageId}');
        const box = row.getBoundingClientRect();

        row.dispatchEvent(new PointerEvent('{$type}', {
            pointerId: 1,
            pointerType: 'touch',
            isPrimary: true,
            bubbles: true,
            cancelable: true,
            clientX: Math.round(box.x + 40),
            clientY: Math.round(box.y + 8),
        }));
    })()
    JS;

    $page->script($press('pointerdown'));
    $page = $page->wait(0.7);
    $page->script($press('pointerup'));

    return $page;
}

/**
 * Open the create-channel dialog at the given viewport. It is the plainest of
 * the form dialogs — a header, three fields and a pair of actions — so it stands
 * in for the whole set that shares the primitive.
 */
function openCreateChannelDialog(AwaitableWebpage $page, int $width, int $height, string $url): AwaitableWebpage
{
    $page = $page->resize($width, $height)->navigate($url);

    // Below the breakpoint the dock is a Sheet, so its rows only exist once it
    // is opened; from `md` up the rail is always mounted.
    if ($width < 768) {
        $page = $page->click('@sidebar-toggle');
    }

    return $page->click('@create-channel-trigger')->assertPresent('@create-channel-submit');
}

/**
 * A script resolving once a query param on the URL reaches the given value —
 * `null` for the param being gone.
 *
 * Every URL assertion in the browser plugin (`assertQueryStringHas`,
 * `assertQueryStringMissing`, `assertPathIs`) is a one-shot read of the page's
 * URL with no retry, and the writes this guards are client-side visits whose
 * history entry lands a tick after the DOM has already moved. Polling one
 * animation frame at a time settles that without pinning a sleep to whatever a
 * loaded CI runner happens to take (#947).
 */
function queryParamSettles(string $param, ?string $expected): string
{
    $wanted = $expected === null ? 'null' : "'{$expected}'";

    return <<<JS
    (async () => {
        const settled = () => new URLSearchParams(location.search).get('{$param}') === {$wanted};

        for (let frame = 0; frame < 120 && ! settled(); frame++) {
            await new Promise(requestAnimationFrame);
        }

        return settled();
    })()
    JS;
}

/**
 * A script resolving once the URL's `nav` param reaches the given destination —
 * `null` for the param being gone. Lives here rather than beside the
 * destinations suite because a second file needs it: the "You" destination has
 * to prove the rail's popover leaves `?nav=` alone (#942).
 */
function navParamSettles(?string $destination): string
{
    return queryParamSettles('nav', $destination);
}

/**
 * A script resolving once exactly `$count` elements match `$selector`.
 *
 * `assertScript` is a one-shot evaluation with no retry, the same trap
 * `queryParamSettles()` covers on the URL side. A dock panel opens on a
 * client-side switch and then fetches its own props with `reset:`, so between
 * its first row appearing (which `assertPresent` waits for) and the list
 * settling there is a window where the merged page is being replaced and the
 * list is momentarily empty. On a loaded parallel runner the one-shot read
 * lands inside that window and sees 0 (#965).
 *
 * Polling stops at the first frame the count matches, which is the settled
 * value in practice: the transient the panel passes through is the empty list,
 * never a different non-zero total.
 */
function elementCountSettles(string $selector, int $count): string
{
    $needle = json_encode($selector, JSON_THROW_ON_ERROR);

    return <<<JS
    (async () => {
        const settled = () => document.querySelectorAll({$needle}).length === {$count};

        for (let frame = 0; frame < 120 && ! settled(); frame++) {
            await new Promise(requestAnimationFrame);
        }

        return settled();
    })()
    JS;
}

/**
 * A JS expression for an element's bounding box, or `null` while it is absent.
 *
 * The usual probe to hand `geometrySettles()`, including for something on its
 * way out — whose settled state is being gone.
 */
function elementBox(string $selector): string
{
    $needle = json_encode($selector, JSON_THROW_ON_ERROR);

    return "document.querySelector({$needle})?.getBoundingClientRect() ?? null";
}

/**
 * A script resolving once the page has stopped moving under it: every one of
 * `$probes` — JS expressions, compared by their JSON form — has held the same
 * value across two consecutive animation frames.
 *
 * This is the geometry counterpart of `queryParamSettles()`, and it closes the
 * same trap. `assertPresent` returns the moment a panel or popover reaches the
 * DOM, which is a whole open animation before its geometry is final, and
 * `assertScript` is a one-shot read that never retries — so what has to cover
 * that animation is the settle in between. A fixed `->wait()` is a guess at how
 * long it takes, and a CSS animation only advances as frames are committed, so
 * on a CPU-starved runner it takes considerably longer than its nominal duration
 * and the window is missed (#775, #1049). Waiting on the frames themselves
 * adapts to whatever the runner can deliver.
 *
 * Pass every value the assertion depends on, not just the moving element: a
 * measurement republished across a window of its own can still be in flight
 * while the element it describes already looks settled.
 */
function geometrySettles(string ...$probes): string
{
    $sample = implode(",\n            ", array_map(
        static fn (string $probe): string => "() => ({$probe})",
        $probes,
    ));

    return <<<JS
    (async () => {
        const probes = [
            {$sample},
        ];
        const sample = () => JSON.stringify(probes.map((probe) => probe()));

        let previous = sample();
        let stable = 0;

        for (let frame = 0; frame < 120; frame++) {
            await new Promise(requestAnimationFrame);

            const current = sample();

            stable = current === previous ? stable + 1 : 0;
            previous = current;

            // Two frames agreeing rather than one: a measurement republished on
            // a frame of its own can hold still across a single frame before it
            // has landed on its final value (see useRailInset).
            if (stable === 2) {
                return true;
            }
        }

        return false;
    })()
    JS;
}

/**
 * Pin a live page to its settled styles by killing transitions and animations.
 *
 * axe reads `getComputedStyle` off whatever frame it lands on, so a tween in
 * flight is reported as if it were the shipped colour — a "contrast miss"
 * between two states that each pass, against a blended background matching no
 * token in the palette. #946 chased that through the theme flip; the same trap
 * catches any audit that follows a control changing state, such as the threads
 * filter pills swapping foreground and background on `aria-pressed` (#965).
 * Applying `transition: none` also cancels whatever is already running, so the
 * elements snap to their target styles rather than finishing the tween.
 */
function suppressTransitions(AwaitableWebpage $page): AwaitableWebpage
{
    $page->script(<<<'JS'
    () => {
        const settled = document.createElement('style');
        settled.textContent =
            '*, *::before, *::after { transition: none !important; animation: none !important; }';
        document.head.appendChild(settled);
    }
    JS);

    return $page;
}

/**
 * Repaint a live page in the dark palette, settled enough for an axe audit.
 *
 * Persisting to localStorage first keeps the appearance controller from
 * re-resolving `system` back to light. Suppressing transitions first is the part
 * that makes the audit trustworthy: swapping the palette re-tints every element
 * carrying `transition-colors`, and #946 was four "contrast misses" in the
 * mobile drawer that were each an interpolation between the light and dark
 * values of `--muted-foreground`, one of them measured against a background
 * matching no token in the palette at all. The ratios moved run to run, which no
 * real token pair can do. Pinning the audit to the settled palette is the only
 * thing worth asserting; it also removes the fade-settle that the dialog audits
 * needed for the same reason (#775). See suppressTransitions().
 */
function switchToDarkTheme(AwaitableWebpage $page): AwaitableWebpage
{
    $page = suppressTransitions($page);

    $page->script(<<<'JS'
    () => {
        localStorage.setItem('appearance', 'dark');
        document.documentElement.classList.add('dark');
        document.documentElement.style.colorScheme = 'dark';
    }
    JS);

    return $page->wait(0.5);
}

/**
 * Sign a user in through the real login form, returning the page so the caller
 * can continue driving it. Each `visit()` gets its own browser context (isolated
 * cookie jar), so two calls yield two independently authenticated clients.
 */
function signInThroughBrowser(User $user, string $password = 'password'): AwaitableWebpage
{
    return visit('/login')
        ->type('#email', $user->email)
        ->type('#password', $password)
        ->click('@login-button')
        // Wait for the post-login redirect to settle before the caller navigates,
        // otherwise a follow-up visit can race the session cookie and bounce back
        // to /login. assertPathIsNot retries within the browser timeout.
        ->assertPathIsNot('/login');
}
