<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;
use Illuminate\Translation\PotentiallyTranslatedString;

class NotExecutableFile implements ValidationRule
{
    /**
     * Reject files a web server could execute or interpret.
     *
     * Checked both ways: the client-supplied extension and the extension guessed
     * from the file's actual content (its mime type). A `.php` renamed to `.txt`
     * still guesses back to `php` and is rejected, so the denylist can't be
     * bypassed by lying about the name. Files are stored outside the webroot too,
     * so this is defence in depth rather than the only safeguard.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile) {
            return;
        }

        /** @var list<string> $denylist */
        $denylist = config('attachments.executable_denylist');

        $extensions = array_filter([
            strtolower($value->getClientOriginalExtension()),
            strtolower((string) $value->guessExtension()),
        ]);

        foreach ($extensions as $extension) {
            if (in_array($extension, $denylist, true)) {
                $fail(__('This file type is not allowed.'));

                return;
            }
        }
    }
}
