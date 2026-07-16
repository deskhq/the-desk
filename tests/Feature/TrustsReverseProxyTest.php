<?php

test('honors the proxy X-Forwarded-Proto so absolute URLs are https', function (): void {
    // A guest hitting an auth-guarded route is redirected to an absolute login
    // URL, so the redirect scheme reflects how the app perceives the request.
    // Without trusting the proxy this comes out http:// on an https:// page and
    // the browser blocks it as mixed content.
    $response = $this->get('/t/example-team', ['X-Forwarded-Proto' => 'https']);

    $response->assertRedirect();
    expect($response->headers->get('Location'))->toStartWith('https://');
});

test('generates http URLs when the request is not a forwarded https one', function (): void {
    $response = $this->get('/t/example-team');

    $response->assertRedirect();
    expect($response->headers->get('Location'))->toStartWith('http://');
});
