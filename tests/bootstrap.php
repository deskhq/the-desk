<?php

declare(strict_types=1);

use Tests\Support\ForcedEnvPins;

require __DIR__.'/../vendor/autoload.php';

/*
 * PHPUnit has already applied the `<php>` pins from phpunit.xml by the time it
 * loads this script, but a `force="true"` pin only reaches putenv() and $_ENV.
 * Drop the $_SERVER copy an exported variable would leave behind, so the pin —
 * not the export — is what the suite resolves. See Tests\Support\ForcedEnvPins.
 */
ForcedEnvPins::clearServerCopies(__DIR__.'/../phpunit.xml');
