<?php

namespace App\Support;

use Illuminate\Support\Facades\File;

class TranslationCatalog
{
    /**
     * The message catalog for a locale (source string → translation).
     *
     * Mirrors Laravel's JSON translations: an unknown or missing locale resolves
     * to an empty catalog, so consumers fall back to the source string (the key).
     *
     * @return array<string, string>
     */
    public function messages(string $locale): array
    {
        $path = lang_path("{$locale}.json");

        if (! File::exists($path)) {
            return [];
        }

        /** @var array<string, string> $messages */
        $messages = (array) json_decode(File::get($path), true);

        return $messages;
    }
}
