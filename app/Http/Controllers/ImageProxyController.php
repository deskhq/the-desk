<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\Images\FetchRemoteImage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ImageProxyController extends Controller
{
    /**
     * Serve a remote image from the app's own origin.
     *
     * The route is authenticated *and* signed: the session keeps it off the
     * public internet, and the signature — checked by the `signed` middleware
     * before this runs — pins the `url` parameter to one the server generated,
     * so a member cannot turn the endpoint into an open proxy or an SSRF probe.
     * Anything the fetcher declines (a non-public host, a non-image, an oversized
     * body, an unreachable origin) is a 404, never a 500, so an instance without
     * egress simply shows the initials avatar and no link thumbnail.
     */
    public function __invoke(Request $request, FetchRemoteImage $fetchRemoteImage): StreamedResponse
    {
        $url = trim((string) $request->query('url', ''));

        $image = $url === '' ? null : $fetchRemoteImage->handle($url);

        abort_if($image === null, 404);

        return Storage::disk(FetchRemoteImage::DISK)->response(
            $image['path'],
            null,
            [
                'Content-Type' => $image['mime'],
                'X-Content-Type-Options' => 'nosniff',
                'Cache-Control' => 'private, max-age='.FetchRemoteImage::CACHE_TTL_SECONDS,
            ],
            'inline',
        );
    }
}
