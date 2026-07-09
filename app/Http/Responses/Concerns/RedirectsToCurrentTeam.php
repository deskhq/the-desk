<?php

namespace App\Http\Responses\Concerns;

use App\Models\Team;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

trait RedirectsToCurrentTeam
{
    /**
     * Resolve the path to the current team's channels workspace (#general).
     */
    protected function redirectPathForCurrentTeam(Request $request): string
    {
        $team = $this->currentTeam($request);

        URL::defaults(['current_team' => $team->slug]);

        return route('channels.index', ['team' => $team->slug], absolute: false);
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

    protected function currentTeam(Request $request): Team
    {
        $user = $request->user();

        abort_if(! $user, 403);

        $team = $user->currentTeam ?? $user->personalTeam();

        abort_if(! $team, 403);

        return $team;
    }
}
