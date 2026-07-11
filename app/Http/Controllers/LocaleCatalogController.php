<?php

namespace App\Http\Controllers;

use App\Enums\AppLocale;
use App\Support\TranslationCatalog;
use Illuminate\Http\JsonResponse;

class LocaleCatalogController extends Controller
{
    public function __construct(protected TranslationCatalog $catalog) {}

    /**
     * Return a locale's message catalog as cacheable JSON, so the client can
     * fetch it once when switching language and serve it from cache thereafter.
     */
    public function show(string $locale): JsonResponse
    {
        abort_unless(AppLocale::tryFrom($locale) instanceof AppLocale, 404);

        $messages = $this->catalog->messages($locale);

        // Revalidate rather than hold the catalog for a fixed window: an
        // ETag makes an unchanged catalog a cheap 304, while a changed one
        // (new translations) is never served stale from a browser's cache.
        return response()
            ->json($messages)
            ->setEtag(md5((string) json_encode($messages)))
            ->header('Cache-Control', 'no-cache');
    }
}
