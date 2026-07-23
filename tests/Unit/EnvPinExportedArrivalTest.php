<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

/*
 * The arrival mode the pins used to lose to (#756): a genuinely exported
 * variable. PHPUnit writes a `force="true"` pin to putenv() and $_ENV only, so
 * the export's $_SERVER entry — which Laravel's env repository reads first —
 * survived and won. It cannot be reproduced in-process (the export has to
 * predate the run), so this re-runs EnvPinArrivalTest in a child process with
 * the values exported into it, exactly as `sail exec -e` or `docker run -e`
 * would deliver them.
 */

test('a force-pinned env variable exported into the process still resolves to its pin', function (): void {
    $process = new Process(
        [PHP_BINARY, 'vendor/bin/pest', '--colors=never', 'tests/Unit/EnvPinArrivalTest.php'],
        dirname(__DIR__, 2),
        [
            'DEMO_MODE' => 'true',
            'REVERB_HOST_PUBLIC' => 'exported.example',
            'REVERB_PORT_PUBLIC' => '20012',
            'REVERB_SCHEME_PUBLIC' => 'http',
        ],
    );

    $process->setTimeout(120)->run();

    $this->assertSame(
        0,
        $process->getExitCode(),
        'The exported values leaked past the pins:'.PHP_EOL.$process->getOutput().$process->getErrorOutput(),
    );
});
