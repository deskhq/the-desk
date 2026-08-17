<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\Branding\BrandingAssets;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class BrandingAssetController extends Controller
{
    public function __construct(private readonly BrandingAssets $assets) {}

    /**
     * Serve one of the branded assets at its canonical URL, preferring the
     * operator's override over the shipped default.
     *
     * The route constrains `{asset}` to the known set, so an unrecognised name
     * never reaches this method and no request can walk out of the branding
     * directory.
     */
    public function show(Request $request, string $asset): BinaryFileResponse
    {
        return $this->serve($request, $this->assets->path($asset));
    }

    /**
     * Serve the operator's logo mark. There is no shipped file to fall back to
     * — the default mark is the inline SVG component — so an instance that has
     * not been rebranded 404s here, and the client renders the inline mark.
     */
    public function logo(Request $request): BinaryFileResponse
    {
        $path = $this->assets->logoPath();

        abort_if($path === null, 404);

        return $this->serve($request, $path);
    }

    /**
     * Send a resolved file with a validator rather than a freshness window: an
     * operator who drops in a new mark sees it on the next request, while an
     * unchanged asset costs a 304 instead of its bytes.
     */
    private function serve(Request $request, string $path): BinaryFileResponse
    {
        $response = response()
            ->file($path, [
                'Content-Type' => $this->assets->mimeType($path),
                'X-Content-Type-Options' => 'nosniff',
                'Cache-Control' => 'no-cache',
            ])
            ->setAutoEtag();

        $response->isNotModified($request);

        return $response;
    }
}
