<?php

declare(strict_types=1);

use App\Http\Controllers\Webhooks\IncomingWebhookController;

/**
 * The incoming-webhook reference page told integrators to sign with
 * `X-Desk-Signature` — the header *outgoing* deliveries are signed with, which
 * the ingest endpoint never reads and whose documented `t=…,v1=…` value would
 * not parse here anyway. Anyone following it on a webhook created with a signing
 * secret got a bare **401** with no hint as to why, and only signed webhooks —
 * the security-conscious setups — were affected. These tests hold every
 * operator-facing page to the header the controller actually reads, and keep the
 * two headers from being confused for one another again. See #1060.
 */
$repoRoot = dirname(__DIR__, 2);

$incomingPage = $repoRoot.'/docs/src/content/docs/reference/incoming-webhooks.md';
$togglesPage = $repoRoot.'/docs/src/content/docs/reference/feature-toggles.md';

/**
 * The header the ingest controller reads an incoming signature from — the source
 * of truth the docs have to mirror.
 */
$incomingHeader = function (): string {
    $header = (new ReflectionClass(IncomingWebhookController::class))->getConstant('SIGNATURE_HEADER');

    return is_string($header) ? $header : '';
};

/**
 * The header an outgoing delivery is signed with, read off the job that sets it
 * rather than restated here, so a rename cannot quietly split the two apart.
 */
$outgoingHeader = function () use ($repoRoot): string {
    preg_match(
        "/'(?<header>X-[A-Za-z0-9-]+)' => WebhookSignature::header\(/",
        (string) file_get_contents($repoRoot.'/app/Jobs/DeliverWebhook.php'),
        $matches,
    );

    return $matches['header'] ?? '';
};

test('the two webhook signature headers are distinct names', function () use ($incomingHeader, $outgoingHeader): void {
    // Guard the guards below: a missed constant or a refactored job would leave
    // every "the page names it" assertion matching the empty string.
    expect($incomingHeader())->not->toBe('')
        ->and($outgoingHeader())->not->toBe('')
        ->and($incomingHeader())->not->toBe($outgoingHeader());
});

test('the incoming-webhook page signs with the header the ingest endpoint reads', function () use ($incomingPage, $incomingHeader): void {
    expect((string) file_get_contents($incomingPage))->toContain($incomingHeader());
});

test('the feature-toggles page names the same incoming signature header', function () use ($togglesPage, $incomingHeader): void {
    expect((string) file_get_contents($togglesPage))->toContain($incomingHeader());
});

test('the incoming-webhook page names the outgoing header only to tell them apart', function () use ($incomingPage, $outgoingHeader): void {
    // Paragraphs rather than lines, because the prose is hard-wrapped: the word
    // that disambiguates a mention routinely sits on a neighbouring line.
    $paragraphs = preg_split('/\n\s*\n/', (string) file_get_contents($incomingPage)) ?: [];

    $mentions = array_filter($paragraphs, fn (string $paragraph): bool => str_contains($paragraph, $outgoingHeader()));
    $ambiguous = array_filter($mentions, fn (string $paragraph): bool => ! str_contains($paragraph, 'outgoing'));

    // Both halves matter: the page has to draw the distinction *and* never name
    // the outgoing header as the one to sign with.
    expect($mentions)->not->toBe([])
        ->and(array_values($ambiguous))->toBe([]);
});
