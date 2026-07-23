<?php

test('every page emits open graph and twitter card meta tags', function (): void {
    $html = $this->get('/login')->assertOk()->getContent();

    $appName = config('app.name');

    expect($html)
        ->toContain('<meta property="og:title" content="'.$appName.'">')
        ->toContain('<meta property="og:site_name" content="'.$appName.'">')
        ->toContain('<meta property="og:description" content="'.__('Open source, self-hosted team chat').'">')
        ->toContain('<meta property="og:type" content="website">')
        ->toContain('<meta property="og:image" content="'.url('/og-image.png').'">')
        ->toContain('<meta property="og:image:width" content="1200">')
        ->toContain('<meta property="og:image:height" content="630">')
        ->toContain('<meta name="twitter:card" content="summary_large_image">');

    expect(url('/og-image.png'))->toStartWith('http');
});

test('the favicon set ships the branded stack mark', function (): void {
    $svg = file_get_contents(public_path('favicon.svg'));

    expect($svg)
        ->toContain('20,4 36,13 20,22 4,13')
        ->toContain('#c9a35c')
        ->toContain('prefers-color-scheme: dark')
        ->toContain('#f3efe4');

    expect(file_get_contents(public_path('favicon.ico')))->toStartWith("\x00\x00\x01\x00");

    [$width, $height] = getimagesize(public_path('apple-touch-icon.png'));
    expect($width)->toBe(180)->and($height)->toBe(180);
});

test('a 1200x630 open graph image is served from public', function (): void {
    [$width, $height, $type] = getimagesize(public_path('og-image.png'));

    expect($width)->toBe(1200)
        ->and($height)->toBe(630)
        ->and($type)->toBe(IMAGETYPE_PNG);
});
