<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;

/**
 * Inheritance is a decision here, not a default.
 *
 * `app/` had 39 final classes out of 530: every Action, every `Data` object,
 * every `app/Support/` read-model was open to extension by accident rather than
 * by design, and an open class is a seam anyone may bind to — including a test,
 * which is how a subclass becomes load-bearing without anyone choosing it.
 *
 * So the default is closed, and the exceptions are the two ways a class earns
 * being open: something already extends it (the base classes below are abstract
 * or a documented parent), or the suite mocks it by name — Mockery builds its
 * doubles by subclassing, so a final class cannot be mocked at all.
 *
 * Reopening a class is a one-word edit plus a line in {@see mockedAppClasses()}
 * if a double is why. What this stops is the third case: a class left open
 * because nobody said otherwise.
 */
$sourceRoot = dirname(__DIR__, 2);

/**
 * The classes the suite hands to Mockery by name, which therefore cannot be
 * final. Prefer a real instance, or a fake bound into the container, over
 * growing this list.
 *
 * @return array<int, string>
 */
function mockedAppClasses(): array
{
    return ['AuditRecorder', 'BrandingAssets', 'PostMessage', 'ProvisionSsoUser'];
}

/**
 * The classes a package mocks on our behalf, where no call in `tests/` names
 * them. `Sanctum::actingAs()` mocks whatever `Sanctum::personalAccessTokenModel()`
 * resolves to, so our token model is subclassed by every API test that passes
 * abilities (vendor/laravel/sanctum/src/Sanctum.php).
 *
 * @return array<int, string>
 */
function frameworkMockedAppClasses(): array
{
    return ['PersonalAccessToken'];
}

/**
 * Every short class name that appears after `extends` in code we own, which is
 * what "something already extends it" means. Short names over-approximate on
 * purpose: two classes sharing one leaf name leaves both open, and leaving a
 * class open is the safe direction for a guard to be wrong in.
 *
 * @return array<int, string>
 */
$extendedNames = function () use ($sourceRoot): array {
    $files = (new Finder)->files()
        ->in([
            $sourceRoot.'/app',
            $sourceRoot.'/tests',
            $sourceRoot.'/database',
            $sourceRoot.'/routes',
            $sourceRoot.'/config',
        ])
        ->name('*.php');

    $names = [];

    foreach ($files as $file) {
        preg_match_all('/extends\s+\\\\?([A-Za-z0-9_\\\\]+)/', $file->getContents(), $matches);

        foreach ($matches[1] as $name) {
            $parts = explode('\\', $name);
            $names[] = end($parts);
        }
    }

    return array_values(array_unique($names));
};

/**
 * Every class `app/` declares that is neither final nor abstract, as
 * `path => name` pairs. Interfaces, traits and enums are not classes and never
 * appear here; an anonymous class has no declaration to match.
 *
 * @return array<string, string>
 */
$openClasses = function () use ($sourceRoot): array {
    $files = (new Finder)->files()->in($sourceRoot.'/app')->name('*.php');

    $open = [];

    foreach ($files as $file) {
        if (preg_match('/^(?:readonly )?class ([A-Za-z0-9_]+)/m', $file->getContents(), $matches) === 1) {
            $open[str_replace($sourceRoot.'/', '', $file->getPathname())] = $matches[1];
        }
    }

    ksort($open);

    return $open;
};

test('every app class nothing extends is final', function () use ($openClasses, $extendedNames): void {
    $allowed = array_merge($extendedNames(), mockedAppClasses(), frameworkMockedAppClasses());

    $unexplained = array_keys(array_filter(
        $openClasses(),
        fn (string $name): bool => ! in_array($name, $allowed, true),
    ));

    expect($unexplained)->toBe([]);
});

/**
 * The other half of a trustworthy guard: the allowlist has to stay honest, or
 * it becomes the place a class goes to avoid the rule. A name only belongs
 * there while the suite really does mock it.
 */
test('every class exempted for mocking is still mocked by name', function (string $class) use ($sourceRoot): void {
    $files = (new Finder)->files()->in($sourceRoot.'/tests')->name('*.php');

    $mocks = false;

    foreach ($files as $file) {
        if (preg_match('/(?:mock|spy)\(\s*'.$class.'::class/i', $file->getContents()) === 1) {
            $mocks = true;

            break;
        }
    }

    expect($mocks)->toBeTrue();
})->with(mockedAppClasses());
