<?php

declare(strict_types=1);

use Pest\Browser\Execution;
use Pest\Browser\Playwright\Client;
use Pest\Browser\Playwright\Playwright;
use PHPUnit\Framework\ExpectationFailedException;

/**
 * pest-plugin-browser v4.3.1 runs every retry attempt of
 * `Execution::waitForExpectation()` under a hardcoded 1000ms protocol budget.
 * On CPU-starved runners (GitHub-hosted `ubuntu-latest`) those 1s attempts
 * expire routinely, queueing stale error responses on the singleton websocket
 * and re-driving non-idempotent clicks — the two flake signatures of issue
 * #786. `tests/Browser/Support/Execution.php` shadows the vendor class (it is
 * required from `tests/Pest.php` before the autoloader can load the original)
 * and gives each attempt the remaining outer budget instead. These tests pin
 * the shadow so a dependency bump or a moved `require` cannot silently bring
 * the 1s budget back.
 */
it('loads the patched Execution shadow instead of the vendor class', function (): void {
    $file = new ReflectionClass(Execution::class)->getFileName();

    expect($file)->toBe(dirname(__DIR__).'/Browser/Support/Execution.php');
});

it('gives every retry attempt the remaining outer budget instead of 1000ms', function (): void {
    $previousTimeout = Playwright::timeout();
    Playwright::setTimeout(15_000);

    /** @var list<int> $attemptBudgets */
    $attemptBudgets = [];

    try {
        Execution::instance()->waitForExpectation(function () use (&$attemptBudgets): int {
            $attemptBudgets[] = Client::instance()->timeout();

            throw_if(count($attemptBudgets) === 1, ExpectationFailedException::class, 'force a retry');

            return Client::instance()->timeout();
        });
    } finally {
        Playwright::setTimeout($previousTimeout);
    }

    expect($attemptBudgets)->toHaveCount(2)
        ->and($attemptBudgets[0])->toBeGreaterThan(1_000)->toBeLessThanOrEqual(15_000)
        ->and($attemptBudgets[1])->toBeGreaterThan(1_000)->toBeLessThanOrEqual($attemptBudgets[0]);
});

it('still retries a failing expectation until the outer clock expires', function (): void {
    $previousTimeout = Playwright::timeout();
    Playwright::setTimeout(100);

    $attempts = 0;
    $startedAt = hrtime(true);

    try {
        Execution::instance()->waitForExpectation(function () use (&$attempts): void {
            $attempts++;

            throw new ExpectationFailedException('never holds');
        });

        $this->fail('The expectation should not have been met.');
    } catch (ExpectationFailedException) {
        // The final bare attempt rethrows once the outer clock has expired.
    } finally {
        Playwright::setTimeout($previousTimeout);
    }

    $elapsedMilliseconds = (hrtime(true) - $startedAt) / 1_000_000;

    expect($attempts)->toBeGreaterThanOrEqual(2)
        ->and($elapsedMilliseconds)->toBeGreaterThanOrEqual(100);
});
