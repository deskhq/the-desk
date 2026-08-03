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
 * of truth the docs have to mirror. It fails loudly rather than degrading to an
 * empty needle, which every `toContain()` below would match.
 */
$incomingHeader = function (): string {
    $header = (new ReflectionClass(IncomingWebhookController::class))->getConstant('SIGNATURE_HEADER');

    throw_if(! is_string($header) || $header === '', RuntimeException::class, 'IncomingWebhookController::SIGNATURE_HEADER no longer names a request header.');

    return $header;
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

    throw_if(($matches['header'] ?? '') === '', RuntimeException::class, 'DeliverWebhook no longer signs a delivery under a recognisable header.');

    return $matches['header'];
};

/**
 * The page's "Signing (optional)" section, so an incidental mention elsewhere
 * cannot stand in for the instructions an integrator actually follows.
 */
$signingSection = function (string $page): string {
    preg_match('/^## Signing.*?(?=^## |\z)/ms', (string) file_get_contents($page), $matches);

    return $matches[0] ?? '';
};

test('the two webhook signature headers are distinct names', function () use ($incomingHeader, $outgoingHeader): void {
    expect($incomingHeader())->not->toBe($outgoingHeader());
});

test('the signing section tells integrators to use the header the ingest endpoint reads', function () use ($incomingPage, $signingSection, $incomingHeader): void {
    expect($signingSection($incomingPage))->toContain($incomingHeader());
});

test('the copy-paste signing sample never signs under the outgoing header', function () use ($incomingPage, $signingSection, $incomingHeader, $outgoingHeader): void {
    preg_match_all('/^```.*?\n(?<body>.*?)^```/ms', $signingSection($incomingPage), $matches);

    $samples = $matches['body'];

    // The fenced sample is what gets pasted into a CI job, so it carries the
    // whole cost of naming the wrong header.
    expect($samples)->not->toBe([])
        ->and(implode("\n", $samples))->toContain($incomingHeader())
        ->and(implode("\n", $samples))->not->toContain($outgoingHeader());
});

test('the incoming-webhook page names the outgoing header only to tell them apart', function () use ($incomingPage, $outgoingHeader): void {
    // Paragraphs rather than lines, because the prose is hard-wrapped: the word
    // that disambiguates a mention routinely sits on a neighbouring line.
    $paragraphs = preg_split('/\n\s*\n/', (string) file_get_contents($incomingPage)) ?: [];

    $mentions = array_filter($paragraphs, fn (string $paragraph): bool => str_contains($paragraph, $outgoingHeader()));
    $ambiguous = array_filter($mentions, fn (string $paragraph): bool => ! str_contains($paragraph, 'outgoing'));

    // Both halves matter: the page has to draw the distinction *and* never leave
    // the outgoing header sitting there as if it were the one to send.
    expect($mentions)->not->toBe([])
        ->and(array_values($ambiguous))->toBe([]);
});

/**
 * The timestamp half of the scheme is what makes a captured request expire, and
 * a sender only sends it because the page says to. Both the prose and the
 * copy-paste sample are held to the controller's own constants so a rename or a
 * retuned window cannot leave integrators signing the replayable way. See #1176.
 */
$constant = function (string $name): string {
    $value = (new ReflectionClass(IncomingWebhookController::class))->getConstant($name);

    throw_if($value === false, RuntimeException::class, "IncomingWebhookController::{$name} no longer exists.");

    return (string) $value;
};

test('the signing section documents the timestamp header and its tolerance window', function () use ($incomingPage, $signingSection, $constant): void {
    $section = $signingSection($incomingPage);

    expect($section)->toContain($constant('TIMESTAMP_HEADER'))
        // Stated in minutes, because it is what an integrator sizes a retry and
        // a clock-skew budget against.
        ->and($section)->toContain(intdiv((int) $constant('TIMESTAMP_TOLERANCE'), 60).' minutes');
});

test('the copy-paste signing sample sends the timestamp it signs', function () use ($incomingPage, $signingSection, $constant): void {
    preg_match_all('/^```.*?\n(?<body>.*?)^```/ms', $signingSection($incomingPage), $matches);

    $samples = implode("\n", $matches['body']);

    // A sample that signs `"{timestamp}.{body}"` but forgets to send the header
    // produces a 401 nobody can diagnose from the page.
    expect($samples)->toContain($constant('TIMESTAMP_HEADER'));
});

test('the feature-toggles page names the same incoming signature header', function () use ($togglesPage, $incomingHeader): void {
    expect((string) file_get_contents($togglesPage))->toContain($incomingHeader());
});
