<?php

declare(strict_types=1);

use App\Support\Gravatar;

test('it derives a gravatar url from the md5 hash of the normalised email', function (): void {
    config()->set('gravatar.enabled', true);
    config()->set('gravatar.base_url', 'https://www.gravatar.com/avatar');
    config()->set('gravatar.size', 200);
    config()->set('gravatar.default', '404');

    // Known-good hash from Gravatar's own docs for "  MyEmailAddress@example.com ".
    $expectedHash = md5('myemailaddress@example.com');

    expect(Gravatar::url('  MyEmailAddress@example.com '))
        ->toBe("https://www.gravatar.com/avatar/{$expectedHash}?d=404&s=200");
});

test('it honours the configured size and default', function (): void {
    config()->set('gravatar.enabled', true);
    config()->set('gravatar.base_url', 'https://gravatar.example/avatar');
    config()->set('gravatar.size', 96);
    config()->set('gravatar.default', 'mp');

    $hash = md5('person@example.com');

    expect(Gravatar::url('person@example.com'))
        ->toBe("https://gravatar.example/avatar/{$hash}?d=mp&s=96");
});

test('it url-encodes the default so a url default is safe to pass through', function (): void {
    config()->set('gravatar.enabled', true);
    config()->set('gravatar.base_url', 'https://www.gravatar.com/avatar');
    config()->set('gravatar.size', 200);
    config()->set('gravatar.default', 'https://example.com/fallback.png');

    $url = Gravatar::url('person@example.com');

    expect($url)->toContain('d=https%3A%2F%2Fexample.com%2Ffallback.png');
});

test('it applies an explicit default override in place of the configured default', function (): void {
    config()->set('gravatar.enabled', true);
    config()->set('gravatar.base_url', 'https://www.gravatar.com/avatar');
    config()->set('gravatar.size', 200);
    config()->set('gravatar.default', '404');

    $hash = md5('person@example.com');

    expect(Gravatar::url('person@example.com', 'identicon'))
        ->toBe("https://www.gravatar.com/avatar/{$hash}?d=identicon&s=200");
});

test('it returns null when gravatar is disabled', function (): void {
    config()->set('gravatar.enabled', false);

    expect(Gravatar::url('person@example.com'))->toBeNull();
});
