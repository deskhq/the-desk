<?php

declare(strict_types=1);

use App\Support\MessageSnippet;

test('wraps the matched term in a mark and leaves a short body whole', function (): void {
    expect(MessageSnippet::highlight('the quokka danced at dawn', 'quokka'))
        ->toBe('the <mark>quokka</mark> danced at dawn');
});

test('highlights every occurrence regardless of case', function (): void {
    expect(MessageSnippet::highlight('Quokka QUOKKA quokka', 'quokka'))
        ->toBe('<mark>Quokka</mark> <mark>QUOKKA</mark> <mark>quokka</mark>');
});

test('wraps each of several query terms', function (): void {
    expect(MessageSnippet::highlight('the quokka met a zephyr', 'quokka zephyr'))
        ->toBe('the <mark>quokka</mark> met a <mark>zephyr</mark>');
});

test('escapes html so a snippet can never inject markup', function (): void {
    expect(MessageSnippet::highlight('<script>alert(1)</script> quokka', 'quokka'))
        ->toBe('&lt;script&gt;alert(1)&lt;/script&gt; <mark>quokka</mark>');
});

test('returns the body head unmarked when no term matches literally', function (): void {
    // The engine can match on stemming/typo tolerance where the raw term is
    // absent; the helper then simply shows a clean, escaped window with no marks.
    expect(MessageSnippet::highlight('nothing relevant here', 'zephyr'))
        ->toBe('nothing relevant here');
});

test('an empty query yields the escaped body head with no marks', function (): void {
    expect(MessageSnippet::highlight('<b>plain</b> body', ''))
        ->toBe('&lt;b&gt;plain&lt;/b&gt; body');
});

test('centers the window on the match with ellipses on both sides', function (): void {
    $body = str_repeat('a', 200).' quokka '.str_repeat('b', 200);

    $snippet = MessageSnippet::highlight($body, 'quokka');

    expect($snippet)->toStartWith('…')
        ->and($snippet)->toEndWith('…')
        ->and($snippet)->toContain('<mark>quokka</mark>')
        // The 200 leading a's are windowed away, so only a tail of them remains.
        ->and($snippet)->not->toContain(str_repeat('a', 200));
});

test('highlights multibyte matches without corrupting surrounding text', function (): void {
    expect(MessageSnippet::highlight('café serves café', 'café'))
        ->toBe('<mark>café</mark> serves <mark>café</mark>');
});

test('merges overlapping term matches into a single mark', function (): void {
    // "quok" is a prefix of "quokka"; the two matches overlap and must not nest.
    expect(MessageSnippet::highlight('a quokka here', 'quokka quok'))
        ->toBe('a <mark>quokka</mark> here');
});

test('turns the engine highlight sentinels into marks and escapes the rest', function (): void {
    $formatted = '<b>keep</b> '.MessageSnippet::HIGHLIGHT_PRE_TAG.'zephyr'.MessageSnippet::HIGHLIGHT_POST_TAG.' note';

    expect(MessageSnippet::fromFormatted($formatted))
        ->toBe('&lt;b&gt;keep&lt;/b&gt; <mark>zephyr</mark> note');
});
