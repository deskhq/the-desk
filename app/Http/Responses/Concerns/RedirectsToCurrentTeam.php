<?php

declare(strict_types=1);

namespace App\Http\Responses\Concerns;

use App\Models\Team;
use App\Models\User;
use App\Support\WorkspaceRedirect;
use Illuminate\Http\Request;

trait RedirectsToCurrentTeam
{
    /**
     * Resolve the path to the current team's channels workspace (#general).
     */
    protected function redirectPathForCurrentTeam(Request $request): string
    {
        $path = WorkspaceRedirect::pathFor($request->user());

        abort_if($path === null, 403);

        return $path;
    }

    /**
     * Drop the stored "intended" URL when the authenticated user cannot view
     * it, so login falls back to a valid workspace instead of a 404/403.
     */
    protected function forgetUnreachableIntendedUrl(Request $request): void
    {
        if (! $request->hasSession()) {
            return;
        }

        $intended = $request->session()->get('url.intended');

        if (is_string($intended) && ! $this->intendedUrlIsReachable($request->user(), $intended)) {
            $request->session()->forget('url.intended');
        }
    }

    /**
     * Determine whether the authenticated user can actually reach a stored
     * workspace URL. Non-workspace targets are honoured as-is; a `/t/{team}`
     * URL requires membership, and a `/t/{team}/c/{channel}` URL additionally
     * requires the channel to exist within that team (scoped bindings 404).
     */
    protected function intendedUrlIsReachable(?User $user, string $intended): bool
    {
        $path = parse_url($intended, PHP_URL_PATH);

        if (! $user || ! is_string($path)) {
            return false;
        }

        // The site root is the public marketing page (route `home`), never a
        // destination for someone who has just signed in — honouring it is how
        // an authenticated user lands back on the Welcome screen.
        if (trim($path, '/') === '') {
            return false;
        }

        $segments = explode('/', trim($path, '/'));

        if ($segments[0] !== 't') {
            return true;
        }

        $team = ($slug = $segments[1] ?? null) ? Team::where('slug', $slug)->first() : null;

        if (! $team || ! $user->belongsToTeam($team)) {
            return false;
        }

        if (($segments[2] ?? null) !== 'c') {
            return true;
        }

        return ($channelSlug = $segments[3] ?? null) !== null
            && $team->channels()->where('slug', $channelSlug)->exists();
    }
}
