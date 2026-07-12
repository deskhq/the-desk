<?php

test('the catalog endpoint returns the locale messages as cacheable json', function (): void {
    $response = $this->get('/locales/fr.json');

    $response->assertOk();

    expect($response->json())->toBeArray();
    expect($response->headers->get('Cache-Control'))->toContain('no-cache');
    expect($response->headers->get('ETag'))->not->toBeNull();
});

test('the catalog endpoint rejects an unknown locale', function (): void {
    $this->get('/locales/xx.json')->assertNotFound();
});
