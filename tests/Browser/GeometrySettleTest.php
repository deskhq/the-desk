<?php

declare(strict_types=1);

/**
 * The frame-polling settle the geometry assertions across this suite lean on
 * (#1049).
 *
 * It is worth pinning on its own, because its failure mode is silence: a settle
 * that resolved true unconditionally would leave every caller reading a
 * mid-animation box again, and each of them would still pass on an idle machine.
 * So both answers are asserted here — that a page at rest settles, and that
 * something which never stops moving is reported rather than waited out.
 */
test('a page that has stopped moving settles', function (): void {
    visit('/login')
        ->assertPresent('@login-button')
        ->assertScript(geometrySettles(elementBox('[data-test="login-button"]')), true);
});

test('something that never stops moving comes back unsettled', function (): void {
    // A probe reading differently on every frame is the shape of an animation
    // that never lands, so the poll gives up and says so instead of hanging.
    visit('/login')
        ->assertScript(geometrySettles('performance.now()'), false);
});
