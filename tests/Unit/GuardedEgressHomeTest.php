<?php

declare(strict_types=1);

use App\Support\Http\GuardedEgress;
use App\Support\Http\OutboundUrlGuard;
use Symfony\Component\Finder\Finder;

/**
 * {@see OutboundUrlGuard} answers three questions that only mean anything asked
 * together — is this URL public, what does it resolve to, how must the
 * connection be pinned — and before #1196 three call sites asked them by hand.
 * Two of the three then spelled the same forty-five line hop-and-pin walk, and
 * only one of those caught a transport failure, so an unreachable host degraded
 * to no image on one path and requeued the job on the other. The pin itself was
 * asserted at one call site of three.
 *
 * {@see GuardedEgress} is now the one home, and this test is what keeps it that
 * way: a fourth site asking the guard directly, or opening its own connection,
 * fails here rather than in production on whichever surface was missed. See
 * ADR-0015.
 */
$sourceRoot = dirname(__DIR__, 2);

/**
 * Every file under `app/` matching the given pattern, as repository-relative
 * paths.
 *
 * @return list<string>
 */
function appFilesMatching(string $sourceRoot, string $pattern): array
{
    $found = [];

    foreach ((new Finder)->files()->in($sourceRoot.'/app')->name('*.php') as $file) {
        if (preg_match($pattern, $file->getContents()) === 1) {
            $found[] = str_replace($sourceRoot.'/', '', $file->getPathname());
        }
    }

    sort($found);

    return $found;
}

/**
 * The delivery-time half of the guard, called on an instance. The declarations
 * themselves read `public function resolveDeliveryIp(`, so they do not match.
 */
function guardTriplePattern(): string
{
    return '/->\s*(resolveDeliveryIp|transportOptions)\(/';
}

/**
 * Reaching the HTTP client at all, in any of the three forms that would let a
 * new site open its own connection: the facade by its short name (which also
 * catches the fully-qualified call, since the leading `\` is a word boundary),
 * the facade aliased to another name on import, and the underlying client
 * factory injected directly.
 *
 * The facade's test-only entry points are excluded, so this can be replayed
 * against a fake below without the tripwire firing on it.
 */
function outboundClientPattern(): string
{
    $facade = '\bHttp::(?!fake|response|assert|preventStrayRequests|allowStrayRequests)\w+\(';
    $aliased = 'use\s+Illuminate\\\\Support\\\\Facades\\\\Http\s+as\s';
    $factory = 'use\s+Illuminate\\\\Http\\\\Client\\\\Factory\b';

    return "/{$facade}|{$aliased}|{$factory}/";
}

test('the guard\'s delivery-time pair is asked from exactly one place', function () use ($sourceRoot): void {
    expect(appFilesMatching($sourceRoot, guardTriplePattern()))->toBe([
        'app/Support/Http/GuardedEgress.php',
    ]);
});

/**
 * `isPublic()` is deliberately not in that list. It is a *pre-flight* question —
 * `PublicWebhookUrl` asks it at validation time, before any request exists, and
 * `User::routeNotificationForWebPush` asks it where the web-push package owns
 * the connection — so it stays public and static, and callers outside this
 * module are expected.
 */
test('the pre-flight check is still asked from outside the module', function () use ($sourceRoot): void {
    expect(appFilesMatching($sourceRoot, '/OutboundUrlGuard::isPublic\(/'))
        ->toContain('app/Rules/PublicWebhookUrl.php', 'app/Models/User.php');
});

/**
 * The five sites that hold the HTTP client, and why each one is allowed to.
 *
 * `GuardedEgress` is the module. `DeliverWebhook` *composes* a signed request
 * and hands it to `GuardedEgress::send()` — it never dispatches one itself.
 * `GiphyClient` and `UpdateChecker` talk to operator-configured hosts and are
 * out of scope by decision, not by oversight (ADR-0015 records why: routing them
 * through a guard each would need to switch off is how a security module stops
 * being one).
 *
 * `HttpUnfurler` is the fifth, added by ADR-0016, and it earns its place on
 * exactly the criterion the two above established: it dials
 * `config('unfurl.url')`, a service on the container network that the operator
 * configured. The member-controlled URL travels in the *body* of that request,
 * and what opens a connection to it is the guard inside `services/unfurler` —
 * which is why the claim this file protects is still true rather than merely
 * still asserted. Sending it through `GuardedEgress::send()` instead would need
 * the guard switched off for a private container address, which is the move
 * ADR-0015 rules out.
 *
 * A sixth entry means a new egress site. Route it through `GuardedEgress` and
 * this test stays quiet; if it genuinely belongs beside Giphy, say so here and
 * in the ADR.
 */
test('the sites holding the HTTP client are the five that are meant to', function () use ($sourceRoot): void {
    expect(appFilesMatching($sourceRoot, outboundClientPattern()))->toBe([
        'app/Jobs/DeliverWebhook.php',
        'app/Support/GiphyClient.php',
        'app/Support/Http/GuardedEgress.php',
        'app/Support/Unfurl/HttpUnfurler.php',
        'app/Support/UpdateChecker.php',
    ]);
});

/**
 * A guard nothing can trip proves nothing, and these are regular expressions
 * over source: a typo makes them match nothing at all and the suite stays green.
 * Each pattern is replayed here against the copy it was written for.
 */
test('each pattern still catches the code it was written for', function (string $pattern, string $source): void {
    expect(preg_match($pattern, $source))->toBe(1);
})->with([
    'the resolve half' => [guardTriplePattern(), '$pinnedIp = $this->guard->resolveDeliveryIp($target);'],
    'the pin half' => [guardTriplePattern(), '->withOptions($this->guard->transportOptions($url, $pinnedIp))'],
    'a fetch' => [outboundClientPattern(), '$response = Http::timeout(self::TIMEOUT_SECONDS)->get($url);'],
    'a composed delivery' => [outboundClientPattern(), "Http::withHeaders(['X-Desk-Event' => \$type])"],
    // The three ways round the short name, which a tripwire keyed to `Http::`
    // alone would wave through.
    'a fully-qualified call' => [outboundClientPattern(), '\Illuminate\Support\Facades\Http::get($url);'],
    'an aliased import' => [outboundClientPattern(), 'use Illuminate\Support\Facades\Http as Client;'],
    'an injected factory' => [outboundClientPattern(), 'use Illuminate\Http\Client\Factory;'],
]);

/**
 * And what each must stay quiet about, so the guard does not become noise
 * someone silences by widening the expected list.
 */
test('each pattern leaves what it is not about alone', function (string $pattern, string $source): void {
    expect(preg_match($pattern, $source))->toBe(0);
})->with([
    // The declarations, which live on the guard by design.
    'the resolve declaration' => [guardTriplePattern(), 'public function resolveDeliveryIp(string $url): string|false|null'],
    'the pin declaration' => [guardTriplePattern(), 'public function transportOptions(string $url, ?string $pinnedIp): array'],
    // The pre-flight check, which is a different question.
    'the pre-flight check' => [guardTriplePattern(), 'if (! OutboundUrlGuard::isPublic($url)) {'],
    // Faking is what a test does, never what production code does.
    'a fake' => [outboundClientPattern(), "Http::fake(['example.test/*' => Http::response('', 200)]);"],
    'a bare fake' => [outboundClientPattern(), 'Http::fake();'],
    // Importing the facade under its own name is what every allowed site does;
    // it is the *call* that the pattern is keyed to.
    'a plain import of the facade' => [outboundClientPattern(), 'use Illuminate\Support\Facades\Http;'],
]);
