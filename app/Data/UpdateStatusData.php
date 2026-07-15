<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The self-hosted instance's version standing, shared to the frontend so it can
 * surface a low-key "update available" indicator.
 *
 * `current` is always the running release. `latest` and `notesUrl` are only
 * populated once a successful check has cached a result (and never when the
 * check is disabled), so a null `latest` means "unknown", not "up to date".
 */
#[TypeScript]
class UpdateStatusData extends Data
{
    public function __construct(
        public string $current,
        public ?string $latest,
        public bool $updateAvailable,
        public ?string $notesUrl,
    ) {}
}
