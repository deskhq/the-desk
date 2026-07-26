<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Thin, injectable view of which PHP extensions the running image loaded.
 *
 * A diagnostic that reports on the runtime has to be exercisable from a runtime
 * that differs from the one under test — no process can unload an extension to
 * prove it notices. Resolving the question through the container instead of
 * calling `extension_loaded()` inline gives that seam.
 */
class PhpExtensions
{
    /**
     * Whether the named extension is loaded.
     */
    public function loaded(string $extension): bool
    {
        return extension_loaded($extension);
    }

    /**
     * Which of the named extensions are not loaded, in the order given.
     *
     * @param  list<string>  $extensions
     * @return list<string>
     */
    public function missing(array $extensions): array
    {
        return array_values(array_filter(
            $extensions,
            fn (string $extension): bool => ! $this->loaded($extension),
        ));
    }
}
