<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\Branding\WebManifest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WebManifestController extends Controller
{
    /**
     * Serve `/manifest.webmanifest`.
     *
     * Rendered per request from `APP_NAME` and the branded icon URLs, which is
     * what makes an instance that only sets `APP_NAME` install under that name.
     * Revalidated rather than held, like the assets it points at, so a rename
     * reaches an already-installed client on its next manifest fetch.
     */
    public function __invoke(Request $request, WebManifest $manifest): JsonResponse
    {
        $document = $manifest->toArray();

        $response = response()
            ->json($document, 200, ['Content-Type' => 'application/manifest+json'])
            ->setEtag(md5((string) json_encode($document)))
            ->header('Cache-Control', 'no-cache');

        $response->isNotModified($request);

        return $response;
    }
}
