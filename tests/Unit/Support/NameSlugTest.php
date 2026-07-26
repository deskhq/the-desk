<?php

declare(strict_types=1);

use App\Support\NameSlug;

test('a name that slugs to something keeps its slug', function (string $name, string $expected): void {
    expect(NameSlug::make($name, 'thing'))->toBe($expected)
        ->and(NameSlug::distinct($name, 'thing'))->toBe($expected);
})->with([
    'latin' => ['Marketing', 'marketing'],
    'mixed case and spaces' => ['Dev Team', 'dev-team'],
    'cyrillic transliterates' => ['Привет', 'privet'],
    'greek transliterates' => ['Ελλάδα', 'ellada'],
    'latin survives alongside emoji' => ['Launch 🎉', 'launch'],
]);

test('a name that slugs to nothing falls back to the given base', function (string $name): void {
    expect(NameSlug::make($name, 'thing'))->toBe('thing');
})->with([
    'japanese' => ['日本語'],
    'korean' => ['한국어'],
    'hebrew' => ['עברית'],
    'punctuation' => ['<<<'],
    'emoji' => ['🎉🎉'],
]);

test('distinct keeps two unsluggable names apart', function (): void {
    $japanese = NameSlug::distinct('日本語', 'channel');
    $chinese = NameSlug::distinct('中文', 'channel');

    expect($japanese)->not->toBe($chinese)
        ->and($japanese)->toStartWith('channel-')
        ->and($chinese)->toStartWith('channel-');
});

test('distinct is stable for the same name, so a pre-check can reproduce it', function (): void {
    expect(NameSlug::distinct('日本語', 'channel'))->toBe(NameSlug::distinct('日本語', 'channel'));
});

test('a fallback slug is usable as a route key and as a group handle', function (string $name): void {
    expect(NameSlug::distinct($name, 'group'))->toMatch('/^[a-z0-9]+(?:-[a-z0-9]+)*$/');
})->with([
    'japanese' => ['日本語'],
    'punctuation' => ['<<<'],
    'emoji' => ['🎉🎉'],
]);
