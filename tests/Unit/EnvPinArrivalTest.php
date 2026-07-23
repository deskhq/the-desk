<?php

declare(strict_types=1);

/*
 * The assertions EnvPinExportedArrivalTest re-runs in a child process that has
 * DEMO_MODE and the REVERB_*_PUBLIC trio exported, so the force-pinned values
 * are checked against every arrival mode (#756). They hold in an ordinary run
 * too, where this file simply guards that the pins are what the suite resolves.
 */

test('the force-pinned env variables resolve to their phpunit.xml values', function (): void {
    expect($_SERVER)->not->toHaveKey('DEMO_MODE')
        ->and($_SERVER)->not->toHaveKey('REVERB_HOST_PUBLIC')
        ->and($_SERVER)->not->toHaveKey('REVERB_PORT_PUBLIC')
        ->and($_SERVER)->not->toHaveKey('REVERB_SCHEME_PUBLIC')
        ->and(env('DEMO_MODE'))->toBeFalsy()
        ->and(env('REVERB_HOST_PUBLIC'))->toBe('')
        ->and(env('REVERB_PORT_PUBLIC'))->toBe('')
        ->and(env('REVERB_SCHEME_PUBLIC'))->toBe('');
});
