<?php

declare(strict_types=1);
use Tests\Support\ProductionCompose;

/**
 * The SSRF guard now has one home per language: `App\Support\Http\OutboundUrlGuard`
 * fronts webhook delivery and the image proxy, and `services/unfurler`'s
 * `internal/guard` fronts the link unfurl. Two implementations of the rule that
 * decides whether this server will open a connection somewhere a member pointed
 * it — which is the highest-stakes rule in the codebase to get wrong twice.
 *
 * `tests/Fixtures/egress-verdict-cases.json` is the specification. Neither
 * language owns it: `tests/Feature/Support/OutboundUrlGuardTest.php` reads it and
 * so does `services/unfurler/internal/guard/verdicts_test.go`, so a case added on
 * one side of the wire has to satisfy the other. This test is what keeps that
 * true. See ADR-0016, which applies ADR-0010's device a third time.
 *
 * It is a tripwire, not a proof. It cannot know whether the two guards agree on a
 * URL nobody thought to write down; the table is the specification and this only
 * catches the lazy ways back — a consumer quietly dropped, a table emptied, or a
 * table that says "refuse everything" and therefore proves nothing.
 */
$sourceRoot = dirname(__DIR__, 2);

$fixturePath = $sourceRoot.'/tests/Fixtures/egress-verdict-cases.json';

/**
 * The shared case table, decoded.
 *
 * @return array<int, array{name: string, url: string, blocked: bool, scope: string}>
 */
function egressVerdictCases(): array
{
    return json_decode(
        (string) file_get_contents(dirname(__DIR__).'/Fixtures/egress-verdict-cases.json'),
        associative: true,
        flags: JSON_THROW_ON_ERROR,
    );
}

/**
 * How few cases is too few. Not a target — the table has far more than this —
 * but a floor, because every assertion below is driven off the table and a table
 * resolved to nothing would pass all of them without testing anything. Same
 * fail-closed reasoning as {@see ProductionCompose::laravelServices()}.
 */
const MINIMUM_EGRESS_CASES = 30;

test('the shared case table is present and is a real specification', function () use ($fixturePath): void {
    expect($fixturePath)->toBeReadableFile();

    expect(egressVerdictCases())->toHaveCount(count(egressVerdictCases()))
        ->and(count(egressVerdictCases()))->toBeGreaterThanOrEqual(MINIMUM_EGRESS_CASES);
});

test('every case states a name, a URL, a verdict and a scope', function (): void {
    foreach (egressVerdictCases() as $index => $case) {
        expect($case)->toHaveKeys(['name', 'url', 'blocked', 'scope'], "case #{$index}")
            ->and($case['name'])->toBeString()->not->toBe('')
            ->and($case['url'])->toBeString()->not->toBe('')
            ->and($case['blocked'])->toBeBool()
            // `both` is a verdict the two languages must agree on. `go` is one
            // the Go guard is stricter about — a real gap on the PHP side rather
            // than a disagreement, recorded as data so it is not rediscovered as
            // a surprise. There is deliberately no `php` scope: PHP being
            // stricter than the guard fronting member-typed URLs would be a
            // defect, not a note.
            ->and($case['scope'])->toBeIn(['both', 'go']);
    }
});

test('each case is named once, so a row cannot be quietly duplicated instead of fixed', function (): void {
    $names = array_column(egressVerdictCases(), 'name');

    expect(array_unique($names))->toHaveCount(count($names));
});

/**
 * A guard that refuses everything passes every blocked case in the table and is
 * completely useless. The allow cases are what make the table a specification
 * rather than a list of grievances, and they have to survive both an IPv4 and an
 * IPv6 reading.
 */
test('the table keeps allow cases, so a refuse-everything guard cannot pass it', function (): void {
    $allowed = array_values(array_filter(egressVerdictCases(), fn (array $case): bool => $case['blocked'] === false));

    expect($allowed)->not->toBeEmpty()
        ->and(array_column($allowed, 'url'))
        ->toContain('https://8.8.8.8/page')
        ->toContain('https://[2606:4700:4700::1111]/page');
});

test('both languages still read the shared table', function (string $consumer) use ($sourceRoot): void {
    expect($sourceRoot.'/'.$consumer)->toBeReadableFile();

    // Asserted as a boolean rather than with `toContain` on the file's contents:
    // a miss there prints the whole source file into the failure, which buries
    // the one line that matters.
    expect(str_contains((string) file_get_contents($sourceRoot.'/'.$consumer), 'egress-verdict-cases.json'))
        ->toBeTrue("{$consumer} no longer reads the shared case table");
})->with([
    'php' => 'tests/Feature/Support/OutboundUrlGuardTest.php',
    'go' => 'services/unfurler/internal/guard/verdicts_test.go',
]);

/**
 * The `go`-scoped rows are the standing list of what the PHP guard does not yet
 * refuse on the webhook and image-proxy paths. Losing them all would read as
 * "the two guards agree now" when what actually happened is that someone deleted
 * the evidence. ADR-0016 records them as explicitly out of scope and owed an
 * issue, which is a different thing from resolved.
 */
test('the gaps the PHP guard still has are recorded rather than dropped', function (): void {
    $goOnly = array_column(
        array_filter(egressVerdictCases(), fn (array $case): bool => $case['scope'] === 'go'),
        'url',
    );

    expect($goOnly)->toContain('http://100.64.0.1/x');
});
