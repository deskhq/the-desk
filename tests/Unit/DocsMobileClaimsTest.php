<?php

declare(strict_types=1);

/**
 * The installable PWA and web push shipped (#862, #870, #872, #874, #876) while
 * the comparison page and the FAQ still listed push notifications among the
 * things The Desk cannot do — the same drift #552 fixed for SSO. Correcting the
 * copy is easy; keeping it corrected is what needs a guard, because both pages
 * are honest-about-the-gaps prose that gets re-edited whenever a gap closes.
 * The other half matters just as much: there is still no App Store or Play
 * Store listing, so neither page may swing into claiming a native app. See #887.
 *
 * @return list<string>
 */
$mobileClaimPages = ['comparison.md', 'faq.md'];

$page = fn (string $file): string => (string) file_get_contents(dirname(__DIR__, 2).'/docs/src/content/docs/'.$file);

/**
 * Sentences of the collapsed page text. Markdown wraps mid-sentence, so the
 * source line breaks say nothing about where a claim starts or ends; a period
 * followed by whitespace does, and it leaves "Rocket.Chat" and "v1.16.0" whole.
 *
 * @return list<string>
 */
function sentencesOf(string $markdown): array
{
    return preg_split('/(?<=[.!?])\s+/', (string) preg_replace('/\s+/', ' ', $markdown)) ?: [];
}

/**
 * Pinned by sentence rather than by the exact stale wording, which a reword
 * would slip past: whatever sentence carries the push claim may not also carry
 * a negation. Both stale bullets read "…no dedicated iOS/Android app or push
 * notifications yet", so both were exactly that shape.
 */
test('neither page still claims push notifications are unavailable', function (string $file) use ($page): void {
    $claims = array_values(array_filter(
        sentencesOf($page($file)),
        static fn (string $sentence): bool => (bool) preg_match('/push notifications/i', $sentence)
    ));

    expect($claims)->not->toBeEmpty('the page has to say something about push notifications for this to guard anything')
        ->each->not->toMatch("/\b(no|not|never|without|lacks?|missing|unavailable|isn't|aren't|don't|doesn't)\b/i");
})->with($mobileClaimPages);

test('both pages describe the pwa install and web push instead', function (string $file) use ($page): void {
    expect($page($file))->toContain('PWA')
        // Linking the reference page keeps the configuration documented in one
        // place rather than restated in prose that would drift again.
        ->and($page($file))->toContain('/reference/feature-toggles/#web-push-notifications');
})->with($mobileClaimPages);

/**
 * Dropping the "no native mobile app" entry outright would overclaim in the
 * other direction, on the two pages whose entire premise is being straight
 * about what is missing.
 */
test('both pages still say plainly that there is no store app', function (string $file) use ($page): void {
    expect($page($file))->toMatch('/App Store/i')
        ->and($page($file))->toMatch('/Play Store/i');
})->with($mobileClaimPages);

/**
 * The "As of vX.Y.Z" line sits inside the section being edited, and release-please
 * only restamps lines carrying this annotation.
 */
test('the comparison page keeps the annotation release-please stamps the version into', function () use ($page): void {
    expect($page('comparison.md'))->toMatch('/As of \*\*v\d+\.\d+\.\d+\*\*.*<!-- x-release-please-version -->/');
});
