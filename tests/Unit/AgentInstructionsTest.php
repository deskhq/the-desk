<?php

declare(strict_types=1);

use Laravel\Boost\Contracts\SupportsGuidelines;
use Laravel\Boost\Install\GuidelineWriter;
use Symfony\Component\Yaml\Yaml;

/**
 * `CLAUDE.md` is loaded in full at the start of every session and re-injected
 * after every `/compact`, and the guidance caps it at 200 lines because
 * adherence falls off as the file grows — precisely the failure mode the file
 * exists to prevent (#1067). Conventions that only bite in one layer therefore
 * live in `.claude/rules/`, which is only worth anything with `paths:`
 * frontmatter: a rule without it loads unconditionally, exactly like CLAUDE.md,
 * so the split would buy line count and no context at all. `@path` imports are
 * the same trap, expanded into context at launch alongside the file that
 * references them. These tests pin the budget, the scoping, and the absence of
 * that trap, so none of the three can quietly come undone.
 */
function agentInstructionsRoot(): string
{
    return dirname(__DIR__, 2);
}

/**
 * Every project rule, keyed by its repository-relative path.
 *
 * @return array<string, string>
 */
function projectRules(): array
{
    $directory = new RecursiveDirectoryIterator(
        agentInstructionsRoot().'/.claude/rules',
        FilesystemIterator::SKIP_DOTS | FilesystemIterator::FOLLOW_SYMLINKS,
    );

    $paths = [];

    foreach (new RecursiveIteratorIterator($directory) as $file) {
        if ($file instanceof SplFileInfo && $file->getExtension() === 'md') {
            $paths[] = $file->getPathname();
        }
    }

    sort($paths);

    $relative = array_map(
        static fn (string $path): string => str_replace(agentInstructionsRoot().'/', '', $path),
        $paths,
    );

    return array_combine($relative, array_map(
        static fn (string $path): string => (string) file_get_contents(agentInstructionsRoot().'/'.$path),
        $relative,
    ));
}

/**
 * The parsed YAML frontmatter of a rule, or null when it carries none.
 *
 * @return array<string, mixed>|null
 */
function ruleFrontmatter(string $contents): ?array
{
    if (preg_match('/\A---\n(.*?)\n---\n/s', $contents, $matches) !== 1) {
        return null;
    }

    /** @var array<string, mixed> $frontmatter */
    $frontmatter = (array) Yaml::parse($matches[1]);

    return $frontmatter;
}

/**
 * Markdown with fenced blocks and code spans removed, which is where Claude
 * Code stops looking for `@path` imports too — a backticked `@README` is a
 * literal mention rather than an import.
 */
function withoutCode(string $markdown): string
{
    $stripped = preg_replace('/^```.*?^```/ms', '', $markdown);

    return (string) preg_replace('/`[^`\n]*`/', '', (string) $stripped);
}

test('the always-on instruction file stays inside the 200-line budget', function (): void {
    $lines = count((array) file(agentInstructionsRoot().'/CLAUDE.md'));

    expect($lines)->toBeLessThan(200, "CLAUDE.md is {$lines} lines; move what only bites in one layer to a path-scoped rule");
});

test('the conventions are actually split into project rules', function (): void {
    expect(projectRules())->not->toBeEmpty();
});

test('every project rule is scoped to the files it applies to', function (): void {
    foreach (projectRules() as $path => $contents) {
        $paths = ruleFrontmatter($contents)['paths'] ?? null;

        expect($paths)->toBeArray($path.' must carry `paths:` frontmatter, or it loads at launch like CLAUDE.md and saves nothing')
            ->and($paths)->not->toBeEmpty($path.' must name at least one glob to be scoped at all');

        foreach ((array) $paths as $glob) {
            expect($glob)->toBeString();
        }
    }
});

test('the always-on file no longer carries the generated boost block', function (): void {
    expect((string) file_get_contents(agentInstructionsRoot().'/CLAUDE.md'))
        ->not->toContain('<laravel-boost-guidelines>');
});

/*
 * Boost rewrites only the text between its own tags and honours a configured
 * guidelines path, so pointing it at a rule keeps `boost:install` safe to
 * re-run *and* keeps the frontmatter that scopes the 215 generated lines.
 */
test('boost writes its generated block into a scoped rule', function (): void {
    /** @var array{agents: array{claude_code: array{guidelines_path: string}}} $boost */
    $boost = require agentInstructionsRoot().'/config/boost.php';

    $path = $boost['agents']['claude_code']['guidelines_path'];

    expect($path)->toBe('.claude/rules/laravel-boost.md')
        ->and(projectRules())->toHaveKey($path);

    $contents = projectRules()[$path];

    expect($contents)->toContain('<laravel-boost-guidelines>')
        ->and(strpos($contents, '---'))->toBeLessThan((int) strpos($contents, '<laravel-boost-guidelines>'));
});

/*
 * The scoping only holds as long as the next `boost:install` leaves it alone, so
 * this runs Boost's own writer over a copy of the rule and checks what survives:
 * the frontmatter above the tags, and none of the block that was between them.
 */
test('regenerating the boost block keeps the frontmatter that scopes it', function (): void {
    $rule = projectRules()['.claude/rules/laravel-boost.md'];

    expect(str_contains($rule, '=== foundation rules ==='))
        ->toBeTrue('the sentinel the rewrite is measured against must be in the rule to begin with');

    $path = (string) tempnam(sys_get_temp_dir(), 'boost-rule');
    file_put_contents($path, $rule);

    (new GuidelineWriter(new readonly class($path) implements SupportsGuidelines
    {
        public function __construct(private string $path) {}

        public function guidelinesPath(): string
        {
            return $this->path;
        }

        public function frontmatter(): bool
        {
            return false;
        }

        public function transformGuidelines(string $markdown): string
        {
            return $markdown;
        }
    }))->write('=== regenerated rules ===');

    $rewritten = (string) file_get_contents($path);
    unlink($path);

    expect(ruleFrontmatter($rewritten))->toBe(ruleFrontmatter($rule))
        ->and($rewritten)->toContain('=== regenerated rules ===')
        ->and($rewritten)->not->toContain('=== foundation rules ===');
});

/*
 * Imports read like a split but are expanded at launch, so a CLAUDE.md that
 * leans on them is still paying for every line it appears to have moved out.
 */
test('the split does not lean on imports that load anyway', function (): void {
    $imports = [];
    preg_match_all('/(?<![\w`\/])@[A-Za-z0-9_~][A-Za-z0-9_.\/~-]*/', withoutCode((string) file_get_contents(agentInstructionsRoot().'/CLAUDE.md')), $imports);

    expect($imports[0])->toBe([]);
});
