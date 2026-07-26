<?php

declare(strict_types=1);

/**
 * The repo squash-merges with `squash_merge_commit_title = PR_TITLE`, so the PR
 * title is the commit release-please reads. `commitlint` only validates the PR's
 * individual commits, so a non-Conventional title would otherwise be dropped from
 * CHANGELOG.md and the version bump silently. `.github/workflows/commitlint.yml`
 * gained a `pr-title` job that closes that hole — these tests pin its config to
 * `commitlint.config.mjs` so the two checks can never disagree. See issue #603.
 */
function repositoryFile(string $path): string
{
    return (string) file_get_contents(dirname(__DIR__, 2).'/'.$path);
}

/**
 * The Conventional types `commitlint` accepts, read from its `type-enum` rule.
 *
 * @return list<string>
 */
function commitlintTypes(): array
{
    $config = repositoryFile('commitlint.config.mjs');

    preg_match("/'type-enum':\s*\[\s*2,\s*'always',\s*\[(?<types>.*?)\]/s", $config, $matches);

    preg_match_all("/'([a-z]+)'/", $matches['types'] ?? '', $types);

    return $types[1];
}

/**
 * The header length `commitlint` allows, read from its `header-max-length` rule.
 */
function commitlintHeaderMaxLength(): int
{
    preg_match("/'header-max-length':\s*\[\s*\d+,\s*'always',\s*(?<length>\d+)\s*\]/", repositoryFile('commitlint.config.mjs'), $matches);

    return (int) ($matches['length'] ?? 0);
}

/**
 * The `with:` inputs of the PR-title validation step in the commitlint workflow.
 *
 * @return array{types?: string, subjectPattern?: string}
 */
function pullRequestTitleStepInputs(): array
{
    $workflow = repositoryFile('.github/workflows/commitlint.yml');

    preg_match('/uses: amannn\/action-semantic-pull-request@.*?\n(?<with>.*?)(?=\n {0,6}\S|\z)/s', $workflow, $matches);

    $block = $matches['with'] ?? '';
    $inputs = [];

    preg_match('/^\s*types: \|\n(?<types>(?:\s*\w+\n)+)/m', $block, $typesMatch);
    if (isset($typesMatch['types'])) {
        $inputs['types'] = trim($typesMatch['types']);
    }

    preg_match("/^\s*subjectPattern: '(?<pattern>.*)'$/m", $block, $patternMatch);
    if (isset($patternMatch['pattern'])) {
        $inputs['subjectPattern'] = $patternMatch['pattern'];
    }

    return $inputs;
}

/**
 * Apply the workflow's `subjectPattern` the way the action does: the regex must
 * match the subject in full, not merely somewhere inside it.
 */
function subjectPatternAccepts(string $subject): bool
{
    $pattern = pullRequestTitleStepInputs()['subjectPattern'] ?? '';

    return preg_match('/'.str_replace('/', '\/', $pattern).'/', $subject, $matches) === 1
        && $matches[0] === $subject;
}

test('the PR-title check accepts exactly the types commitlint accepts', function (): void {
    $workflowTypes = explode("\n", pullRequestTitleStepInputs()['types'] ?? '');
    $workflowTypes = array_map(trim(...), $workflowTypes);

    sort($workflowTypes);
    $commitlintTypes = commitlintTypes();
    sort($commitlintTypes);

    expect($commitlintTypes)->toContain('deps')
        ->and($workflowTypes)->toBe($commitlintTypes);
});

test('the PR-title check re-runs when a title is edited', function (): void {
    $workflow = repositoryFile('.github/workflows/commitlint.yml');

    preg_match('/^\s*types: \[(?<types>[^\]]+)\]$/m', $workflow, $matches);

    $triggers = array_map(trim(...), explode(',', $matches['types'] ?? ''));

    expect($triggers)->toContain('opened', 'edited', 'synchronize', 'reopened');
});

test('the PR-title action is pinned to a commit SHA with a version comment', function (): void {
    $workflow = repositoryFile('.github/workflows/commitlint.yml');

    expect($workflow)->toMatch('/uses: amannn\/action-semantic-pull-request@[0-9a-f]{40} # v\d+\.\d+\.\d+/');
});

/**
 * The subject length the workflow pattern still accepts, probed rather than
 * hardcoded so tightening the bound in either file keeps the two honest.
 */
test('the subject pattern keeps a squash header inside commitlint header-max-length', function (): void {
    $longestAcceptedSubject = 0;

    foreach (range(1, 2 * commitlintHeaderMaxLength()) as $length) {
        if (subjectPatternAccepts(str_repeat('a', $length))) {
            $longestAcceptedSubject = $length;
        }
    }

    $longestType = max(array_map(strlen(...), commitlintTypes()));

    expect(commitlintHeaderMaxLength())->toBeGreaterThan(0)
        ->and($longestAcceptedSubject)->toBeGreaterThan(0)
        ->and($longestType + strlen(': ') + $longestAcceptedSubject)->toBeLessThanOrEqual(commitlintHeaderMaxLength());
});

test('the subject pattern mirrors commitlint subject-case and subject-full-stop', function (string $subject, bool $accepted): void {
    expect(subjectPatternAccepts($subject))->toBe($accepted);
})->with([
    'lower-case subject' => ['add a thing', true],
    'digit-led subject' => ['2FA cleanup', true],
    'inner acronym' => ['re-check the SSO policy', true],
    // Verified against commitlint itself: it accepts subjects that open on a
    // symbol or a non-ASCII letter, so the pattern must not whitelist [a-z0-9].
    'code-span-led subject' => ['`useFoo` composable', true],
    'accented subject' => ['émoji support', true],
    'symbol-led subject' => ['-webkit prefix handling', true],
    'sentence-case subject' => ['Add a thing', false],
    'acronym-led subject' => ['SSO login is broken', false],
    'trailing full stop' => ['add a thing.', false],
    'over-long subject' => [str_repeat('a', 101), false],
]);
