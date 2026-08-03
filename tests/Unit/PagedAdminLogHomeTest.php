<?php

declare(strict_types=1);

use App\Support\AuditLog;
use App\Support\LogActors;
use App\Support\SecurityLog;
use App\Support\SimplePage;
use Symfony\Component\Finder\Finder;

/**
 * The paged admin log was written four times, twice in each language (#1199).
 * {@see AuditLog} and {@see SecurityLog} were line-for-line twins across seven
 * concerns, and the envelope leaked to the client as two more copies.
 *
 * {@see SimplePage} is now the one home for the walk and the envelope, and
 * {@see LogActors} the one home for the actor facet. This is what keeps it that
 * way: a third admin log that spells its own `simplePaginate` — or a fourth copy
 * of the `{data, prevPageUrl, nextPageUrl}` keys — fails here, where the drift
 * is cheap to undo, rather than years later when the two have quietly stopped
 * agreeing about what a page is.
 */
$sourceRoot = dirname(__DIR__, 2);

/**
 * Every file under the given directory matching the pattern, as
 * repository-relative paths.
 *
 * @return list<string>
 */
function pagedLogFilesMatching(string $sourceRoot, string $directory, string $extension, string $pattern): array
{
    $found = [];

    foreach (new Finder()->files()->in($sourceRoot.'/'.$directory)->name($extension) as $file) {
        if (preg_match($pattern, $file->getContents()) === 1) {
            $found[] = str_replace($sourceRoot.'/', '', $file->getPathname());
        }
    }

    sort($found);

    return $found;
}

/**
 * Paging a query one page at a time — the walk itself, along with the page size
 * it is asked for.
 */
function simplePaginationWalkPattern(): string
{
    return '/->\s*simplePaginate\(|\bPER_PAGE\b\s*=/';
}

/**
 * The envelope's own keys, in either language. A copy is a copy whether it is
 * spelled in an Inertia payload or in a TypeScript type.
 */
function pageEnvelopePattern(): string
{
    return '/\bprevPageUrl\b|\bnextPageUrl\b/';
}

/**
 * Building a query, in both spellings — through `::query()` and through a model's
 * static passthrough (`AuditActivity::where(...)`), which is the same thing with
 * one call elided and is how the duplication would grow back unnoticed.
 */
function controllerQueryPattern(): string
{
    return '/::query\(|::where\(|::when\(|->where\(|->when\(/';
}

test('the pagination walk and its page size are declared in one place', function () use ($sourceRoot): void {
    expect(pagedLogFilesMatching($sourceRoot, 'app', '*.php', simplePaginationWalkPattern()))->toBe([
        'app/Support/SimplePage.php',
    ]);
});

test('the envelope is declared once on the server', function () use ($sourceRoot): void {
    expect(pagedLogFilesMatching($sourceRoot, 'app', '*.php', pageEnvelopePattern()))->toBe([
        'app/Support/SimplePage.php',
    ]);
});

/**
 * And once on the client. `SimplePage<T>` is a client-only view model of the
 * kind `CONTEXT.md` blesses, not a shadowed DTO — there is no `Page` class in
 * `app/Data/` for it to restate, so ADR-0013 is not what is being asked here.
 * What is being asked is that the two log pages parameterise it instead of
 * re-spelling it.
 */
test('the envelope is declared once on the client', function () use ($sourceRoot): void {
    expect(pagedLogFilesMatching($sourceRoot, 'resources/js', '*.ts', pageEnvelopePattern()))->toBe([
        'resources/js/types/pagination.ts',
    ]);
});

/**
 * The actor facet is the sharpest of the seven duplications: fourteen lines of
 * distinct-pluck-then-name, written out twice. Both logs ask for it here, and a
 * third would get it for free.
 */
test('the actor facet is one module, called by both logs', function () use ($sourceRoot): void {
    expect(pagedLogFilesMatching($sourceRoot, 'app', '*.php', '/new LogActors\(/'))->toBe([
        'app/Support/AuditLog.php',
        'app/Support/SecurityLog.php',
    ]);
});

/**
 * The controllers are glue now: they validate, they name the props, and they
 * compute none of it. A query builder reappearing in either one is the
 * duplication growing back.
 */
test('neither log controller holds a query', function () use ($sourceRoot): void {
    expect(pagedLogFilesMatching($sourceRoot, 'app/Http/Controllers/Teams', '*.php', controllerQueryPattern()))
        ->not->toContain(
            'app/Http/Controllers/Teams/AuditController.php',
            'app/Http/Controllers/Teams/SecurityLogController.php',
        );
});

/**
 * A tripwire nothing can trip proves nothing, and these are regular expressions
 * over source: a typo makes them match nothing and the suite stays green. Each
 * pattern is replayed against the copy it was written to catch.
 */
test('each pattern still catches the code it was written for', function (string $pattern, string $source): void {
    expect(preg_match($pattern, $source))->toBe(1);
})->with([
    'the walk' => [simplePaginationWalkPattern(), '->simplePaginate(self::PER_PAGE)'],
    'the page size' => [simplePaginationWalkPattern(), 'private const int PER_PAGE = 30;'],
    'the prev key' => [pageEnvelopePattern(), "'prevPageUrl' => \$entries->previousPageUrl(),"],
    'the next key' => [pageEnvelopePattern(), '    nextPageUrl: string | null;'],
    'a query built through the builder' => [controllerQueryPattern(), '$entries = AuditActivity::query()'],
    'a query built through the static passthrough' => [controllerQueryPattern(), "AuditActivity::where('team_id', \$team->id)"],
    'a filter applied inline' => [controllerQueryPattern(), "->when(\$action, fn (\$query) => \$query->where('event', \$action))"],
]);

/**
 * And what each must leave alone, so the tripwire does not become noise someone
 * silences by widening the expected list.
 */
test('each pattern leaves what it is not about alone', function (string $pattern, string $source): void {
    expect(preg_match($pattern, $source))->toBe(0);
})->with([
    // A cursor-paged surface is a different envelope for a different pagination
    // mode, and `CONTEXT.md` blesses it: `ThreadInboxPage` and `MessagePage`
    // carry cursors, not page URLs, and are deliberately not retrofitted here.
    'a cursor page' => [pageEnvelopePattern(), "'next_cursor' => \$this->metadata->getNextPage(),"],
    'a cursor paginator' => [simplePaginationWalkPattern(), '->cursorPaginate($perPage)'],
    // Laravel's default length-aware paginator on the REST contract is a third
    // envelope on a different promise. Also not this.
    'the API paginator' => [simplePaginationWalkPattern(), '->paginate(50)'],
    // Reading the page size is not declaring it.
    'reading the page size' => [simplePaginationWalkPattern(), 'expect($entries)->toHaveCount(SimplePage::PER_PAGE);'],
]);
