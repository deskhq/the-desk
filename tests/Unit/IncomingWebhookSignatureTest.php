<?php

declare(strict_types=1);

use App\Models\IncomingWebhook;
use Tests\TestCase;

// The signing secret uses the encrypted cast, which resolves the framework's
// encrypter from the container, so these tests boot the application.
uses(TestCase::class);

it('never matches a signature when the webhook has no signing secret', function (): void {
    $webhook = new IncomingWebhook(['signing_secret' => null]);

    expect($webhook->signatureMatches('anything', '{"body":"hi"}'))->toBeFalse();
});

it('matches only the correct HMAC-SHA256 signature of the raw body', function (): void {
    $secret = 'shhh';
    $body = '{"body":"hi"}';
    $webhook = new IncomingWebhook(['signing_secret' => $secret]);

    expect($webhook->signatureMatches(hash_hmac('sha256', $body, $secret), $body))->toBeTrue()
        ->and($webhook->signatureMatches('wrong', $body))->toBeFalse();
});

it('signs the timestamp together with the body when one is given', function (): void {
    $secret = 'shhh';
    $body = '{"body":"hi"}';
    $timestamp = 1764547200;
    $webhook = new IncomingWebhook(['signing_secret' => $secret]);

    expect($webhook->signatureMatches(hash_hmac('sha256', $timestamp.'.'.$body, $secret), $body, $timestamp))->toBeTrue()
        // The body-only digest signs a different message and cannot stand in for
        // it, which is what stops a captured legacy request from being replayed
        // as a timestamped one.
        ->and($webhook->signatureMatches(hash_hmac('sha256', $body, $secret), $body, $timestamp))->toBeFalse()
        // And the timestamp is bound to the digest: restating it does not help.
        ->and($webhook->signatureMatches(hash_hmac('sha256', $timestamp.'.'.$body, $secret), $body, $timestamp + 1))->toBeFalse();
});
