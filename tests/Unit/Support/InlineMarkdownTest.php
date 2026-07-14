<?php

declare(strict_types=1);

use App\Support\InlineMarkdown;

test('leaves a body without inline code untouched', function (): void {
    expect(InlineMarkdown::maskInlineCode('hello @[Ada](x) world'))
        ->toBe('hello @[Ada](x) world');
});

test('masks the contents of an inline-code span so tokens inside cannot match', function (): void {
    $body = 'run `@[Ada](x) https://a.test` now';
    $masked = InlineMarkdown::maskInlineCode($body);

    // Same length so downstream offsets stay aligned, but the span (fences and
    // all) is blanked so a mention/URL regex finds nothing inside it.
    expect($masked)->toHaveLength(strlen($body))
        ->and($masked)->not->toContain('@[Ada]')
        ->and($masked)->not->toContain('https://a.test')
        ->and($masked)->toBe('run '.str_repeat(' ', 26).' now');
});

test('a backtick with no matching closer stays literal', function (): void {
    expect(InlineMarkdown::maskInlineCode('a `b @[Ada](x)'))
        ->toBe('a `b @[Ada](x)');
});

test('matches only a closing run of the same length', function (): void {
    // A double-backtick span closes on the next ``, not on a single `.
    $body = '``a ` b`` c';
    expect(InlineMarkdown::maskInlineCode($body))
        ->toBe(str_repeat(' ', 9).' c');
});

test('masks each of several code spans independently', function (): void {
    $body = '`x` @[Ada](x) `y`';
    expect(InlineMarkdown::maskInlineCode($body))
        ->toBe('    @[Ada](x)    ');
});
