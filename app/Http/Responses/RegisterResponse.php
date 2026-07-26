<?php

namespace App\Http\Responses;

use App\Enums\PostRegistrationPrompt;
use App\Http\Responses\Concerns\RedirectsToCurrentTeam;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Fortify\Contracts\RegisterResponse as RegisterResponseContract;
use Symfony\Component\HttpFoundation\Response;

class RegisterResponse implements RegisterResponseContract
{
    use RedirectsToCurrentTeam;

    public function toResponse($request): Response
    {
        $this->queuePasskeyPrompt($request);

        if ($request->wantsJson()) {
            return new JsonResponse(['two_factor' => false], 201);
        }

        $this->forgetUnreachableIntendedUrl($request);

        return redirect()->intended($this->redirectPathForCurrentTeam($request));
    }

    /**
     * Queue the post-registration passkey prompt and clear the way for it.
     *
     * Two writes, both session-scoped. The prompt itself is queued unconditionally
     * — whether it can be offered is re-read per request from the shared prop, so
     * an operator flipping the toggle mid-signup is honoured rather than baked in.
     *
     * The second write stamps the password confirmation Fortify only ever performs
     * in `ConfirmablePasswordController`. Passkey enrolment sits behind
     * `RequirePassword`, so without it "Add a passkey" would bounce the user to
     * re-type the password they chose seconds earlier. This records a true fact
     * rather than dodging the guard: `RequirePassword` exists to re-prove a session
     * that may have been hijacked since login, and at registration the session was
     * created *by* the act of choosing that password. The accepted cost is that the
     * normal `auth.password_timeout` window is open on a brand-new account, which
     * is the same window any user gets after one confirmation.
     *
     * Both writes are session state, so a stateless registration — an API client
     * posting with no session on the request — simply skips them.
     *
     * @param  Request  $request
     */
    protected function queuePasskeyPrompt($request): void
    {
        if (! $request->hasSession()) {
            return;
        }

        $request->session()->put([
            PostRegistrationPrompt::SESSION_KEY => PostRegistrationPrompt::Passkey->value,
            'auth.password_confirmed_at' => time(),
        ]);
    }
}
