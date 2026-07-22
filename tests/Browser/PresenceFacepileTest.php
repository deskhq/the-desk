<?php

declare(strict_types=1);

test('every facepile presence dot paints above the avatar overlapping it', function (): void {
    ['owner' => $alice] = browserTeamWithChannel();

    $page = signInThroughBrowser($alice)
        ->assertPresent('[data-test="masthead-member-presence"]');

    // The facepile lays avatars out with a negative gap, so every avatar but
    // the last is partially covered by its successor — exactly where the
    // presence dot sits. Hit-test each dot's centre: the dot itself must be
    // the topmost element there, not the following avatar.
    $hits = $page->script(<<<'JS'
    () => {
        const dots = [
            ...document.querySelectorAll(
                '[data-test="masthead-member-presence"]',
            ),
        ];

        return {
            dots: dots.length,
            unobscured: dots.filter((dot) => {
                const rect = dot.getBoundingClientRect();
                const hit = document.elementFromPoint(
                    rect.left + rect.width / 2,
                    rect.top + rect.height / 2,
                );

                return dot === hit || dot.contains(hit);
            }).length,
        };
    }
    JS);

    // Two members render, so the first dot genuinely sits under the second
    // avatar — a single dot would make this assertion vacuous.
    expect($hits['dots'])->toBeGreaterThanOrEqual(2)
        ->and($hits['unobscured'])->toBe($hits['dots']);
});
