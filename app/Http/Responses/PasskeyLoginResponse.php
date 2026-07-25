<?php

namespace App\Http\Responses;

use App\Http\Responses\Concerns\RedirectsToCurrentTeam;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Passkeys\Contracts\PasskeyLoginResponse as PasskeyLoginResponseContract;
use Symfony\Component\HttpFoundation\Response;

class PasskeyLoginResponse implements PasskeyLoginResponseContract
{
    use RedirectsToCurrentTeam;

    /**
     * Send a completed passwordless sign-in to the current team's workspace.
     *
     * The vendor response reads `config('passkeys.redirect')`, which Fortify
     * fills once at boot from `fortify.home` — a static string that cannot know
     * which team the person signing in belongs to, so every passkey login landed
     * on the public marketing page. Both branches resolve the same destination:
     * the login screen drives the ceremony over JSON and navigates to the
     * `redirect` it gets back, so that is the branch a real browser takes.
     *
     * @param  Request  $request
     */
    public function toResponse($request): Response
    {
        $this->forgetUnreachableIntendedUrl($request);

        $redirect = redirect()->intended($this->redirectPathForCurrentTeam($request));

        return $request->wantsJson()
            ? new JsonResponse(['redirect' => $redirect->getTargetUrl()], 200)
            : $redirect;
    }
}
