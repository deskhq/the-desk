<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;

/**
 * "Resolve the model this route bound, or 404" is one rule, and before #1151 it
 * was written out 53 times across 42 of the 79 files under
 * `app/Http/Requests/` — roughly 250 lines of identical guard, and the reason
 * those files ran 80 to 170 lines when their actual rules were ten.
 *
 * It was not even uniform. Three levels of type safety shipped side by side for
 * the same question: the five-line `abort_if` guard, a `$team instanceof Team &&
 * Gate::allows(...)` inline in `authorize()`, and four requests handing
 * `$this->route('team')` — a `mixed` — straight to the gate.
 *
 * There is now one home, `App\Http\Requests\RouteBoundRequest`, and this test is
 * what keeps a fifty-fourth copy from being written: each of the three shapes
 * fails here rather than in review. See `CONTEXT.md`.
 */
$sourceRoot = dirname(__DIR__, 2);

/**
 * The three shapes route-model resolution took in a form request, each keyed by
 * the copy it came from.
 *
 * @return array<string, string>
 */
function routeModelSpellingPatterns(): array
{
    return [
        // The five-line accessor, copied 53 times.
        'hand-written guard' => '/\$\w+\s*=\s*\$this->route\([^)]*\);\s*abort_if\(\s*!\s*\$\w+\s+instanceof/',
        // `Teams\RequestAuditExportRequest` — the same question, guarded inline.
        'inline instanceof gate' => '/instanceof\s+\w+\s*&&\s*Gate::/',
        // `Teams\DeleteTeamRequest` and the three log-viewing requests — a `mixed`
        // handed to the gate, which is the guard skipped entirely.
        'unguarded gate' => '/Gate::\w+\([^;]*\$this->route\(/',
    ];
}

/**
 * Every form request spelling the given shape out, as repository-relative paths.
 *
 * @return array<int, string>
 */
$spellings = function (string $pattern) use ($sourceRoot): array {
    $files = (new Finder)->files()->in($sourceRoot.'/app/Http/Requests')->name('*.php');

    $found = [];

    foreach ($files as $file) {
        if (preg_match($pattern, $file->getContents()) === 1) {
            $found[] = str_replace($sourceRoot.'/', '', $file->getPathname());
        }
    }

    sort($found);

    return $found;
};

/**
 * The base resolves the model, so it is the one file that still spells the guard
 * out — every other form request reaches the rule through it.
 */
test('route-model resolution is spelled in exactly one place', function () use ($spellings): void {
    $patterns = routeModelSpellingPatterns();

    expect($spellings($patterns['hand-written guard']))->toBe(['app/Http/Requests/RouteBoundRequest.php']);
});

/**
 * The two shapes that skipped the guard leave nothing behind at all: a form
 * request never hands the gate an unresolved route parameter.
 */
test('no form request hands a route parameter straight to the gate', function () use ($spellings): void {
    $patterns = routeModelSpellingPatterns();

    expect($spellings($patterns['inline instanceof gate']))->toBe([])
        ->and($spellings($patterns['unguarded gate']))->toBe([]);
});

/**
 * A guard nothing can trip proves nothing, and these are regular expressions
 * over source: a typo makes one match nothing and the suite stays green. Each
 * shape #1151 removed is replayed here verbatim.
 */
test('each pattern still catches the copy it was written for', function (string $shape, string $source): void {
    expect(preg_match(routeModelSpellingPatterns()[$shape], $source))->toBe(1);
})->with([
    'the five-line accessor' => ['hand-written guard', <<<'PHP'
        public function channel(): Channel
        {
            $channel = $this->route('channel');

            abort_if(! $channel instanceof Channel, 404);

            return $channel;
        }
    PHP],
    'the same guard inline in rules()' => ['hand-written guard', <<<'PHP'
            $team = $this->route('team');
            abort_if(! $team instanceof Team, 404);
    PHP],
    'the inline instanceof gate' => ['inline instanceof gate', <<<'PHP'
            return $team instanceof Team && Gate::allows($this->gate(), $team);
    PHP],
    'the unguarded gate' => ['unguarded gate', <<<'PHP'
            return Gate::allows('viewAudit', $this->route('team'));
    PHP],
]);

/**
 * The other half of a trustworthy tripwire: what it must stay quiet about. A
 * route parameter that is not a bound model, and a gate reached through the
 * accessor, are both what this change produced — flagging either would make the
 * guard noise someone silences.
 */
test('each pattern leaves the converted shape alone', function (string $source): void {
    foreach (routeModelSpellingPatterns() as $pattern) {
        expect(preg_match($pattern, $source))->toBe(0);
    }
})->with([
    // The shape every converted request now takes.
    'a gate reached through the accessor' => ["return Gate::allows('delete', \$this->team());"],
    // `Teams\SaveTeamRequest` — asking whether the route is nested at all.
    'a presence check on the parameter' => ["return \$this->route('team') !== null;"],
    // `Api/V1\AddReactionRequest` — a scalar route segment, not a bound model.
    'a scalar route segment' => ["'emoji' => \$this->route('emoji'),"],
    // Narrowing something that is not a route parameter, which is what
    // `Api/V1\ApiRequest::subject()` still does with the authenticated user.
    'a guard over the authenticated user' => [<<<'PHP'
            $user = $this->user();

            abort_if(! $user instanceof User, 401);
    PHP],
]);
