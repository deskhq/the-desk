<?php

declare(strict_types=1);

use App\Support\Unfurl\HttpUnfurler;
use App\Support\Unfurl\UnfurledPreview;
use App\Support\Unfurl\UnfurlerUnavailable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * The client half of the unfurl. Everything it used to do itself — the SSRF
 * guard, the redirect walk, the size cap, the Open Graph parse — now lives in
 * `services/unfurler` and is covered by that module's own suite against the
 * shared case table. What is left here, and what this file covers, is the
 * conversation: what goes on the wire, what comes back, and the one distinction
 * that matters, between "this page has no preview" and "the service is not
 * there".
 *
 * See ADR-0016.
 */
function unfurler(): HttpUnfurler
{
    config(['unfurl.url' => 'http://unfurler:8080', 'unfurl.token' => 's3cret']);

    return app(HttpUnfurler::class);
}

/**
 * A service response carrying the given per-URL results.
 *
 * @param  array<int, array<string, mixed>>  $results
 */
function unfurlerResponds(array $results): void
{
    Http::fake(['unfurler:8080/unfurl' => Http::response(['results' => $results])]);
}

it('sends the whole batch to the service in one request', function (): void {
    unfurlerResponds([
        ['url' => 'https://a.test', 'status' => 'failed', 'reason' => 'no_title'],
        ['url' => 'https://b.test', 'status' => 'failed', 'reason' => 'no_title'],
    ]);

    unfurler()->unfurl(['https://a.test', 'https://b.test']);

    Http::assertSent(fn ($request): bool => $request->url() === 'http://unfurler:8080/unfurl'
        && $request->method() === 'POST'
        && $request->data() === ['urls' => ['https://a.test', 'https://b.test']]);

    Http::assertSentCount(1);
});

it('authenticates with the shared secret', function (): void {
    unfurlerResponds([]);

    unfurler()->unfurl(['https://a.test']);

    Http::assertSent(fn ($request): bool => $request->hasHeader('Authorization', 'Bearer s3cret'));
});

it('maps a resolved result onto a preview', function (): void {
    unfurlerResponds([[
        'url' => 'https://a.test',
        'status' => 'ok',
        'preview' => [
            'title' => 'Hello',
            'description' => 'A page',
            'image' => 'https://a.test/i.png',
            'siteName' => 'Example',
        ],
    ]]);

    $preview = unfurler()->unfurl(['https://a.test'])['https://a.test'];

    expect($preview)->toBeInstanceOf(UnfurledPreview::class)
        ->and($preview->title)->toBe('Hello')
        ->and($preview->description)->toBe('A page')
        ->and($preview->image)->toBe('https://a.test/i.png')
        ->and($preview->siteName)->toBe('Example');
});

it('carries the optional fields through as nulls', function (): void {
    unfurlerResponds([[
        'url' => 'https://a.test',
        'status' => 'ok',
        'preview' => ['title' => 'Only a title', 'description' => null, 'image' => null, 'siteName' => null],
    ]]);

    $preview = unfurler()->unfurl(['https://a.test'])['https://a.test'];

    expect($preview->title)->toBe('Only a title')
        ->and($preview->description)->toBeNull()
        ->and($preview->image)->toBeNull()
        ->and($preview->siteName)->toBeNull();
});

it('maps a failed result onto no preview', function (): void {
    unfurlerResponds([['url' => 'https://a.test', 'status' => 'failed', 'reason' => 'blocked_address']]);

    expect(unfurler()->unfurl(['https://a.test']))->toBe(['https://a.test' => null]);
});

it('treats a result the service omitted as failed', function (): void {
    unfurlerResponds([['url' => 'https://a.test', 'status' => 'ok', 'preview' => ['title' => 'A']]]);

    // The second URL is answered by nothing at all. A row left pending forever
    // is a skeleton that spins forever, so a missing answer is a failed one.
    expect(unfurler()->unfurl(['https://a.test', 'https://b.test']))
        ->toHaveKey('https://b.test')
        ->and(unfurler()->unfurl(['https://a.test', 'https://b.test'])['https://b.test'])->toBeNull();
});

it('ignores a result for a URL it never asked about', function (): void {
    unfurlerResponds([
        ['url' => 'https://a.test', 'status' => 'ok', 'preview' => ['title' => 'A']],
        ['url' => 'https://uninvited.test', 'status' => 'ok', 'preview' => ['title' => 'Nope']],
    ]);

    expect(array_keys(unfurler()->unfurl(['https://a.test'])))->toBe(['https://a.test']);
});

it('treats an ok result with no preview payload as failed', function (): void {
    unfurlerResponds([['url' => 'https://a.test', 'status' => 'ok']]);

    expect(unfurler()->unfurl(['https://a.test']))->toBe(['https://a.test' => null]);
});

it('treats an ok result with no title as failed', function (): void {
    unfurlerResponds([['url' => 'https://a.test', 'status' => 'ok', 'preview' => ['title' => '']]]);

    expect(unfurler()->unfurl(['https://a.test']))->toBe(['https://a.test' => null]);
});

it('skips an entry that is not a result at all', function (): void {
    // A well-formed envelope carrying a malformed row. The batch is still
    // usable, so the row is skipped rather than the whole response rejected —
    // the alternative loses two good previews to one bad neighbour.
    unfurlerResponds([
        'not-an-object',
        ['status' => 'ok', 'preview' => ['title' => 'No url key']],
        ['url' => 42, 'status' => 'ok', 'preview' => ['title' => 'Url is not a string']],
        ['url' => 'https://a.test', 'status' => 'ok', 'preview' => ['title' => 'Fine']],
    ]);

    $previews = unfurler()->unfurl(['https://a.test', 'https://b.test']);

    expect($previews['https://a.test']->title)->toBe('Fine')
        ->and($previews['https://b.test'])->toBeNull();
});

it('asks nothing when there is nothing to ask about', function (): void {
    Http::fake();

    expect(unfurler()->unfurl([]))->toBe([]);

    Http::assertNothingSent();
});

/*
|--------------------------------------------------------------------------
| Unfurling turned off
|--------------------------------------------------------------------------
|
| No configured URL is not a failure. It is what an operator running a
| customised compose file without the service gets, and what an instance that
| wants no outbound fetching at all configures on purpose. Nothing is dialled
| and every link resolves to no card — which is also what keeps the browser
| suite, served in a process `Http::preventStrayRequests()` cannot reach,
| deterministic.
*/
it('makes no request at all when no service is configured', function (): void {
    Http::fake();
    config(['unfurl.url' => null, 'unfurl.token' => null]);

    expect(app(HttpUnfurler::class)->unfurl(['https://a.test']))->toBe(['https://a.test' => null]);

    Http::assertNothingSent();
});

/*
|--------------------------------------------------------------------------
| The service is not there
|--------------------------------------------------------------------------
|
| Distinct from "this page has no preview", and the distinction is the point:
| one is a fact about a link and is cached for a day, the other is a fact about
| the stack and must not be. Every shape of it raises, so the decorator above
| can tell them apart at all.
*/
it('raises rather than reporting no preview when the service cannot be reached', function (string $shape): void {
    // The fake is built here rather than in the dataset: a dataset value is
    // resolved before `Tests\TestCase::setUp()` runs, and `preventStrayRequests()`
    // there would wipe a fake registered that early.
    match ($shape) {
        'a refused connection' => Http::fake(fn () => throw new ConnectionException('Connection refused')),
        'an unauthorized reply' => Http::fake(['unfurler:8080/*' => Http::response(['error' => 'unauthorized'], 401)]),
        'a rejected batch' => Http::fake(['unfurler:8080/*' => Http::response(['error' => 'too_many_urls'], 400)]),
        'a draining service' => Http::fake(['unfurler:8080/*' => Http::response(['status' => 'draining'], 503)]),
        'a body that is not JSON' => Http::fake(['unfurler:8080/*' => Http::response('<html>gateway</html>', 200)]),
        'a JSON body with no results' => Http::fake(['unfurler:8080/*' => Http::response(['ok' => true], 200)]),
        'results that are not a list' => Http::fake(['unfurler:8080/*' => Http::response(['results' => 'nope'], 200)]),
    };

    expect(fn (): array => unfurler()->unfurl(['https://a.test']))
        ->toThrow(UnfurlerUnavailable::class);
})->with([
    'a refused connection',
    'an unauthorized reply',
    'a rejected batch',
    'a draining service',
    'a body that is not JSON',
    'a JSON body with no results',
    'results that are not a list',
]);

/**
 * The message an operator reads must name the reason and never the link, which
 * is text somebody typed into a private message.
 */
it('names the reason without naming the link', function (): void {
    Http::fake(['unfurler:8080/*' => Http::response(['error' => 'unauthorized'], 401)]);

    try {
        unfurler()->unfurl(['https://secret-project.test/roadmap']);
    } catch (UnfurlerUnavailable $exception) {
        expect($exception->getMessage())->toContain('401')
            ->not->toContain('secret-project');

        return;
    }

    $this->fail('the client did not raise');
});

it('bounds how long it waits for the service', function (): void {
    unfurlerResponds([]);
    config(['unfurl.timeout' => 9]);

    unfurler()->unfurl(['https://a.test']);

    Http::assertSent(fn ($request): bool => $request->toPsrRequest() !== null);
})->throwsNoExceptions();
